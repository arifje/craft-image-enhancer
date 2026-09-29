<?php

namespace arjanbrinkman\craftimageenhancer\jobs;

use arjanbrinkman\craftimageenhancer\helpers\AssetHelper;
use arjanbrinkman\craftimageenhancer\helpers\ChatModelHelper;
use arjanbrinkman\craftimageenhancer\helpers\FaceBoxHelper;
use arjanbrinkman\craftimageenhancer\helpers\FileHelper;
use arjanbrinkman\craftimageenhancer\helpers\HttpHelper;
use arjanbrinkman\craftimageenhancer\helpers\ImageHelper;
use arjanbrinkman\craftimageenhancer\helpers\JobStatus;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\models\Settings;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\queue\BaseJob;
use GuzzleHttp\ClientInterface;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use yii\queue\RetryableJobInterface;

/**
 * Creates a face-blurred preview asset. Faces are detected on a downscaled, auto-oriented
 * copy; boxes are normalized (0-1000) so they apply to the auto-oriented original.
 */
class ArticleImageFaceBlurJob extends BaseJob implements RetryableJobInterface
{
	/** Up to three detection requests (3 x 120s) + local blurring. */
	private const TTR = 600;

	public int $assetId;
	public ?int $userId = null;
	public string $token;
	public bool $useManualFaces = false;
	public array $manualFaces = [];

	public function getTtr(): int
	{
		return self::TTR;
	}

	public function canRetry($attempt, $error): bool
	{
		return false;
	}

	public function execute($queue): void
	{
		$settings = ImageEnhancer::getInstance()->getSettings();
		$localPath = null;
		$analysisPath = null;
		$tempPath = null;
		$previewAsset = null;

		$this->updateStatus('running', 0.05, 'Loading asset');
		$this->setProgress($queue, 0.05, 'Loading asset');

		try {
			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$asset = Craft::$app->getAssets()->getAssetById($this->assetId);
			if (!$asset instanceof Asset || !$this->isSupportedImageAsset($asset)) {
				throw new \RuntimeException('Asset not found or unsupported.');
			}
			if (!class_exists(Imagick::class)) {
				throw new \RuntimeException('Imagick is required to blur faces.');
			}

			$localPath = FileHelper::copyAssetToTemp($asset);

			if ($this->useManualFaces) {
				$this->updateStatus('running', 0.2, 'Preparing manual blur areas', [
					'blurMode' => 'manual',
				]);
				$this->setProgress($queue, 0.2, 'Preparing manual blur areas');
				$faces = FaceBoxHelper::normalizeManualFaceBoxes($this->manualFaces);
			} else {
				$apiKey = $settings->getResolvedChatGptApiKey();
				if ($apiKey === '') {
					throw new \RuntimeException('ChatGPT API key is missing.');
				}

				$this->updateStatus('running', 0.2, 'Detecting faces', [
					'blurMode' => 'auto',
				]);
				$this->setProgress($queue, 0.2, 'Detecting faces');
				$analysisPath = ImageHelper::createAnalysisCopy($localPath);
				$faces = $this->detectFaces(Craft::createGuzzleClient(), $settings, $analysisPath, $apiKey, $queue);
			}

			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			if (empty($faces)) {
				// An expected outcome, not a job failure.
				$this->updateStatus('failed', 1, 'No faces found', [
					'message' => 'No faces found to blur.',
					'faceCount' => 0,
				]);
				$this->setProgress($queue, 1, 'No faces found');
				return;
			}

			$this->updateStatus('running', 0.65, 'Blurring faces');
			$this->setProgress($queue, 0.65, 'Blurring faces');
			$tempPath = $this->blurFacesToTempFile($asset, $localPath, $faces);

			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$this->updateStatus('running', 0.85, 'Saving blurred preview');
			$this->setProgress($queue, 0.85, 'Saving blurred preview');
			$previewAsset = AssetHelper::createAssetFromFile(
				$asset,
				$tempPath,
				AssetHelper::getPreviewFilename($asset, AssetHelper::FACE_BLUR_PREVIEW_MARKER),
				$this->userId ?: $asset->uploaderId,
			);
			if (!$previewAsset instanceof Asset) {
				throw new \RuntimeException('Could not save the blurred preview asset.');
			}

			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$this->updateStatus('complete', 1, 'Blurred preview ready', [
				'previewId' => $previewAsset->id,
				'enhancedUrl' => $this->appendCacheBuster($previewAsset->getUrl()),
				'faceCount' => count($faces),
				'blurMode' => $this->useManualFaces ? 'manual' : 'auto',
			]);

			// A cancel that raced the "complete" write must not leave an orphaned preview.
			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$previewAsset = null;
			$this->setProgress($queue, 1, 'Blurred preview ready');
		} catch (\Throwable $e) {
			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$this->updateStatus('failed', 1, 'Face blur failed', [
				'message' => HttpHelper::describeForUser($e),
			]);
			$this->setProgress($queue, 1, 'Face blur failed');
			Craft::error('ImageEnhancer: Article image face blur queue job failed: ' . HttpHelper::describe($e) . ' ' . HttpHelper::describeForUser($e), __METHOD__);

			if ($e instanceof \Error) {
				throw $e;
			}
		} finally {
			if ($previewAsset instanceof Asset) {
				AssetHelper::deleteGeneratedAsset($previewAsset);
			}
			FileHelper::delete($localPath, $analysisPath, $tempPath);
		}
	}

	/**
	 * Detects faces on the analysis copy, trying the configured model and two fallbacks.
	 *
	 * @throws \RuntimeException if no model returned a usable answer
	 */
	private function detectFaces(ClientInterface $client, Settings $settings, string $analysisPath, string $apiKey, $queue): array
	{
		$imageData = file_get_contents($analysisPath);
		if ($imageData === false) {
			throw new \RuntimeException('Could not read the image for face detection.');
		}

		$dataUri = 'data:image/jpeg;base64,' . base64_encode($imageData);
		unset($imageData);
		$prompt = ImageEnhancer::getInstance()->runtimeSettings->getFaceBlurDetectionPromptForRequest($settings);
		$models = array_values(array_unique([
			ChatModelHelper::resolveModel($settings),
			'gpt-4o-mini',
			'gpt-4o',
		]));

		foreach ($models as $index => $model) {
			if ($this->isCanceled()) {
				return [];
			}

			$label = $index === 0 ? 'Detecting faces' : 'Retrying face detection';
			$this->updateStatus('running', 0.2 + ($index * 0.12), $label);
			$this->setProgress($queue, 0.2 + ($index * 0.12), $label);

			try {
				$json = ChatModelHelper::createCompletion($client, $apiKey, ChatModelHelper::buildPayload($model, [[
					'role' => 'user',
					'content' => [
						[
							'type' => 'text',
							'text' => $prompt,
						],
						[
							'type' => 'image_url',
							'image_url' => ['url' => $dataUri],
						],
					],
				]], 1200, [
					'response_format' => ['type' => 'json_object'],
				]));
			} catch (\Throwable $e) {
				Craft::warning('ImageEnhancer: Face blur detection attempt failed for model ' . $model . ': ' . HttpHelper::describe($e), __METHOD__);
				continue;
			}

			$content = $json['choices'][0]['message']['content'] ?? '';
			$data = FaceBoxHelper::extractJsonObject(is_string($content) ? $content : '');
			$faces = FaceBoxHelper::normalizeFaceBoxes($data);

			if (!empty($faces)) {
				return $faces;
			}

			if (is_array($data) && array_key_exists('faces', $data) && is_array($data['faces'])) {
				return [];
			}

			Craft::warning('ImageEnhancer: Face blur detection returned an invalid response for model ' . $model, __METHOD__);
		}

		throw new \RuntimeException('Could not detect face positions.');
	}

	/**
	 * Blurs each face region of the auto-oriented original and writes a temp file.
	 *
	 * @throws \ImagickException
	 * @throws \RuntimeException
	 */
	private function blurFacesToTempFile(Asset $asset, string $localPath, array $faces): string
	{
		$tempPath = FileHelper::createTempPathForAsset($asset);
		$image = new Imagick($localPath);
		$output = $image;

		try {
			// Detection and manual boxes both refer to the displayed (oriented) image.
			ImageHelper::autoOrient($image);
			$image->setImagePage(0, 0, 0, 0);

			$imageWidth = $image->getImageWidth();
			$imageHeight = $image->getImageHeight();

			foreach (array_slice($faces, 0, FaceBoxHelper::MAX_FACES) as $face) {
				$box = FaceBoxHelper::toPixels($face, $imageWidth, $imageHeight);
				if ($box['width'] < 2 || $box['height'] < 2) {
					continue;
				}

				// Copy only the face region instead of cloning the whole image per face.
				$sourceRegion = $image->getImageRegion($box['width'], $box['height'], $box['x'], $box['y']);
				$sourceRegion->setImagePage(0, 0, 0, 0);
				$fragmentedRegion = $this->createFragmentedFaceRegion($sourceRegion);
				$mask = $this->createHeadShapeMask($box['width'], $box['height']);

				try {
					$fragmentedRegion->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
					$fragmentedRegion->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);
					$image->compositeImage($fragmentedRegion, Imagick::COMPOSITE_OVER, $box['x'], $box['y']);
				} finally {
					$sourceRegion->clear();
					$fragmentedRegion->clear();
					$mask->clear();
				}
			}

			// Keeps the ICC profile; the orientation tag was reset by autoOrient().
			$output = ImageHelper::applyOutputFormat($image, (string) $asset->mimeType, 90);
			if (!$output->writeImage($tempPath)) {
				throw new \RuntimeException('Could not write the blurred image.');
			}
		} catch (\Throwable $e) {
			FileHelper::delete($tempPath);
			throw $e;
		} finally {
			if ($output !== $image) {
				$output->clear();
			}
			$image->clear();
		}

		return $tempPath;
	}

	private function createFragmentedFaceRegion(Imagick $sourceRegion): Imagick
	{
		$width = $sourceRegion->getImageWidth();
		$height = $sourceRegion->getImageHeight();
		$fragmentedRegion = clone $sourceRegion;
		$blockSize = max(5, (int) round(min($width, $height) / 10));
		$smallWidth = max(1, (int) ceil($width / $blockSize));
		$smallHeight = max(1, (int) ceil($height / $blockSize));

		$fragmentedRegion->resizeImage($smallWidth, $smallHeight, Imagick::FILTER_POINT, 1);
		$fragmentedRegion->resizeImage($width, $height, Imagick::FILTER_POINT, 1);
		$fragmentedRegion->gaussianBlurImage(0, max(1.2, min($width, $height) * 0.025));

		return $fragmentedRegion;
	}

	private function createHeadShapeMask(int $width, int $height): Imagick
	{
		$mask = new Imagick();
		$mask->newImage($width, $height, new ImagickPixel('transparent'), 'png');

		$draw = new ImagickDraw();
		$draw->setFillColor(new ImagickPixel('white'));
		$draw->ellipse(
			$width / 2,
			$height * 0.5,
			max(1, $width * 0.47),
			max(1, $height * 0.48),
			0,
			360
		);

		$mask->drawImage($draw);
		$mask->blurImage(0, max(0.6, min($width, $height) * 0.015));
		$draw->clear();

		return $mask;
	}

	private function isSupportedImageAsset(Asset $asset): bool
	{
		return $asset->kind === Asset::KIND_IMAGE &&
			in_array($asset->mimeType, ['image/jpeg', 'image/jpg', 'image/png'], true);
	}

	private function appendCacheBuster(?string $url): ?string
	{
		if (!$url) {
			return null;
		}

		return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . time();
	}

	private function updateStatus(string $status, float $progress, string $progressLabel, array $extra = []): void
	{
		if ($status !== 'canceled' && $this->isCanceled()) {
			return;
		}

		JobStatus::merge($this->token, $this->assetId, array_merge([
			'status' => $status,
			'assetId' => $this->assetId,
			'token' => $this->token,
			'operation' => 'blurFaces',
			'progress' => $progress,
			'progressLabel' => $progressLabel,
		], $extra));
	}

	private function isCanceled(): bool
	{
		return JobStatus::isCanceled($this->token);
	}

	private function finishCanceled($queue): void
	{
		$this->updateStatus('canceled', 1, 'Canceled');
		$this->setProgress($queue, 1, 'Canceled');
	}

	private function getRelatedEntryForAsset(int $assetId): ?Entry
	{
		$sourceId = (new Query())
			->select(['r.sourceId'])
			->from(['r' => Table::RELATIONS])
			->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[r.sourceId]]')
			->where(['r.targetId' => $assetId])
			->andWhere(['e.revisionId' => null, 'e.dateDeleted' => null])
			->scalar();

		if (!$sourceId) {
			return null;
		}

		$element = Craft::$app->getElements()->getElementById((int) $sourceId, null, '*');
		if (!$element) {
			return null;
		}

		if ($element instanceof Entry) {
			return $this->normalizeEntry($element);
		}

		$ownerId = $element->ownerId ?? null;
		if (!$ownerId) {
			return null;
		}

		$owner = Entry::find()
			->id($ownerId)
			->site('*')
			->status(null)
			->drafts(null)
			->one();

		return $owner instanceof Entry ? $this->normalizeEntry($owner) : null;
	}

	private function normalizeEntry(Entry $entry, array $seenEntryIds = []): Entry
	{
		if (in_array((int) $entry->id, $seenEntryIds, true)) {
			return $entry;
		}

		$seenEntryIds[] = (int) $entry->id;
		$ownerId = $entry->ownerId ?? null;

		if ($ownerId) {
			$owner = Entry::find()
				->id($ownerId)
				->site('*')
				->status(null)
				->drafts(null)
				->one();

			if ($owner instanceof Entry) {
				return $this->normalizeEntry($owner, $seenEntryIds);
			}
		}

		$canonicalId = $entry->canonicalId ?? null;
		if ($canonicalId && (int) $canonicalId !== (int) $entry->id) {
			$canonical = Entry::find()
				->id($canonicalId)
				->site('*')
				->status(null)
				->one();

			if ($canonical instanceof Entry) {
				return $this->normalizeEntry($canonical, $seenEntryIds);
			}
		}

		return $entry;
	}

	private function truncateTitle(string $title, int $limit = 25): string
	{
		$title = trim($title);
		if (mb_strlen($title) <= $limit) {
			return $title;
		}

		return rtrim(mb_substr($title, 0, max(0, $limit - 3))) . '...';
	}

	protected function defaultDescription(): string
	{
		$title = $this->getRelatedEntryForAsset($this->assetId)?->title ?? null;
		$title = $title ? $this->truncateTitle($title) : null;

		return $title ? 'Blur faces in article image preview: ' . $title : 'Blur faces in article image preview';
	}
}
