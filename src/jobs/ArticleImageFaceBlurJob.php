<?php

namespace arjanbrinkman\craftimagequalitychecker\jobs;

use arjanbrinkman\craftimagequalitychecker\ImageQualityChecker;
use arjanbrinkman\craftimagequalitychecker\models\Settings;
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

class ArticleImageFaceBlurJob extends BaseJob
{
	public int $assetId;
	public ?int $userId = null;
	public string $token;

	public function execute($queue): void
	{
		$settings = ImageQualityChecker::getInstance()->getSettings();
		$this->updateStatus('running', 0.05, 'Loading asset');
		$this->setProgress($queue, 0.05, 'Loading asset');

		try {
			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$asset = Craft::$app->assets->getAssetById($this->assetId);
			if (!$this->isSupportedImageAsset($asset)) {
				throw new \RuntimeException('Asset not found or unsupported.');
			}
			if (!class_exists(Imagick::class)) {
				throw new \RuntimeException('Imagick is required to blur faces.');
			}

			$localPath = $this->getFullAssetPath($asset);
			if (!$localPath || !file_exists($localPath)) {
				throw new \RuntimeException('Could not find the original asset file.');
			}

			$apiKey = trim($settings->chatGptApiKey);
			if ($apiKey === '') {
				throw new \RuntimeException('ChatGPT API key is missing.');
			}

			$this->updateStatus('running', 0.2, 'Detecting faces');
			$this->setProgress($queue, 0.2, 'Detecting faces');
			$faces = $this->detectFaces(Craft::createGuzzleClient(), $settings, $asset, $localPath, $apiKey);
			if (empty($faces)) {
				throw new \RuntimeException('No faces found to blur.');
			}

			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$this->updateStatus('running', 0.65, 'Blurring faces');
			$this->setProgress($queue, 0.65, 'Blurring faces');
			$tempPath = $this->blurFacesToTempFile($asset, $localPath, $faces);

			if ($this->isCanceled()) {
				@unlink($tempPath);
				$this->finishCanceled($queue);
				return;
			}

			$this->updateStatus('running', 0.85, 'Saving blurred preview');
			$this->setProgress($queue, 0.85, 'Saving blurred preview');
			$previewAsset = $this->createPreviewAsset($asset, $tempPath);
			if (!$previewAsset instanceof Asset) {
				@unlink($tempPath);
				throw new \RuntimeException('Could not save the blurred preview asset.');
			}

			$this->updateStatus('complete', 1, 'Blurred preview ready', [
				'previewId' => $previewAsset->id,
				'enhancedUrl' => $this->appendCacheBuster($previewAsset->getUrl()),
				'faceCount' => count($faces),
			]);
			$this->setProgress($queue, 1, 'Blurred preview ready');
		} catch (\Throwable $e) {
			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$this->updateStatus('failed', 1, 'Face blur failed', [
				'message' => $e->getMessage(),
			]);
			Craft::error('ImageQualityChecker: Article image face blur queue job failed: ' . $e->getMessage(), __METHOD__);
			throw $e;
		}
	}

	private function detectFaces(ClientInterface $client, Settings $settings, Asset $asset, string $localPath, string $apiKey): array
	{
		$mime = $asset->mimeType ?: 'image/jpeg';
		$imageBase64 = base64_encode(file_get_contents($localPath));
		$prompt = ImageQualityChecker::getInstance()->runtimeSettings->getFaceBlurDetectionPromptForRequest($settings);
		$models = array_values(array_unique([
			$this->resolveChatGptModel($client, $settings->chatGptModel, $apiKey),
			'gpt-4o-mini',
			'gpt-4o',
		]));

		foreach ($models as $model) {
			try {
				$response = $client->post('https://api.openai.com/v1/chat/completions', [
					'headers' => [
						'Authorization' => 'Bearer ' . $apiKey,
						'Content-Type' => 'application/json',
					],
					'json' => [
						'model' => $model,
						'response_format' => ['type' => 'json_object'],
						'messages' => [[
							'role' => 'user',
							'content' => [
								[
									'type' => 'text',
									'text' => $prompt,
								],
								[
									'type' => 'image_url',
									'image_url' => ['url' => 'data:' . $mime . ';base64,' . $imageBase64],
								],
							],
						]],
						'max_completion_tokens' => 1200,
					],
				]);
			} catch (\Throwable $e) {
				Craft::warning('ImageQualityChecker: Face blur detection attempt failed: ' . $e->getMessage(), __METHOD__);
				continue;
			}

			$json = json_decode((string) $response->getBody(), true);
			$content = $json['choices'][0]['message']['content'] ?? '';
			$data = $this->extractJsonObject((string) $content);
			$faces = $this->normalizeFaceBoxes($data);

			if (!empty($faces)) {
				return $faces;
			}

			if (is_array($data) && array_key_exists('faces', $data)) {
				return [];
			}

			Craft::warning('ImageQualityChecker: Face blur detection returned an invalid response for model ' . $model, __METHOD__);
		}

		throw new \RuntimeException('Could not detect face positions.');
	}

	private function blurFacesToTempFile(Asset $asset, string $localPath, array $faces): string
	{
		$tempPath = $this->getTempReplacementPath($asset);
		$image = new Imagick($localPath);
		$image->setImagePage(0, 0, 0, 0);

		$imageWidth = $image->getImageWidth();
		$imageHeight = $image->getImageHeight();

		foreach ($faces as $face) {
			$box = $this->normalizedFaceBoxToPixels($face, $imageWidth, $imageHeight);
			if ($box['width'] < 2 || $box['height'] < 2) {
				continue;
			}

			$sourceRegion = clone $image;
			$sourceRegion->cropImage($box['width'], $box['height'], $box['x'], $box['y']);
			$sourceRegion->setImagePage(0, 0, 0, 0);

			$fragmentedRegion = $this->createFragmentedFaceRegion($sourceRegion);
			$mask = $this->createHeadShapeMask($box['width'], $box['height']);

			$fragmentedRegion->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
			$fragmentedRegion->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);
			$image->compositeImage($fragmentedRegion, Imagick::COMPOSITE_OVER, $box['x'], $box['y']);

			$sourceRegion->clear();
			$sourceRegion->destroy();
			$fragmentedRegion->clear();
			$fragmentedRegion->destroy();
			$mask->clear();
			$mask->destroy();
		}

		if (in_array($asset->mimeType, ['image/jpeg', 'image/jpg'], true)) {
			$image->setImageCompression(Imagick::COMPRESSION_JPEG);
			$image->setImageCompressionQuality(90);
			$image->setImageFormat('jpeg');
		} elseif ($asset->mimeType === 'image/png') {
			$image->setImageFormat('png');
		}

		$image->writeImage($tempPath);
		$image->clear();
		$image->destroy();

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
		$draw->destroy();

		return $mask;
	}

	private function normalizedFaceBoxToPixels(array $face, int $imageWidth, int $imageHeight): array
	{
		$x = (float) $face['x'] / 1000 * $imageWidth;
		$y = (float) $face['y'] / 1000 * $imageHeight;
		$width = (float) $face['width'] / 1000 * $imageWidth;
		$height = (float) $face['height'] / 1000 * $imageHeight;
		[$x, $y, $width, $height] = $this->coerceFaceBoxToHeadShape($x, $y, $width, $height, $imageWidth, $imageHeight);
		$paddingX = $width * 0.14;
		$paddingY = $height * 0.18;

		$x = max(0, (int) floor($x - $paddingX));
		$y = max(0, (int) floor($y - $paddingY));
		$right = min($imageWidth, (int) ceil($x + $width + ($paddingX * 2)));
		$bottom = min($imageHeight, (int) ceil($y + $height + ($paddingY * 2)));

		return [
			'x' => $x,
			'y' => $y,
			'width' => max(0, $right - $x),
			'height' => max(0, $bottom - $y),
		];
	}

	private function coerceFaceBoxToHeadShape(float $x, float $y, float $width, float $height, int $imageWidth, int $imageHeight): array
	{
		$centerX = $x + ($width / 2);
		$centerY = $y + ($height / 2);
		$ratio = $width / max(1, $height);
		$relativeArea = ($width * $height) / max(1, $imageWidth * $imageHeight);
		$bottom = $y + $height;

		if ($ratio < 0.5) {
			$width = $height * 0.68;
		} elseif ($ratio > 1.35) {
			$height = $width / 1.05;
		}

		if ($height > $width * 1.65) {
			$height = $width * 1.35;
			$centerY = $y + ($height / 2);
		}

		if ($relativeArea > 0.42 && $bottom > $imageHeight * 0.72 && $height > $width * 1.05) {
			$height = min($height, $width * 1.25);
			$centerY = $y + ($height / 2);
		}

		$x = $centerX - ($width / 2);
		$y = $centerY - ($height / 2);

		if ($x < 0) {
			$x = 0;
		}
		if ($y < 0) {
			$y = 0;
		}
		if ($x + $width > $imageWidth) {
			$x = max(0, $imageWidth - $width);
		}
		if ($y + $height > $imageHeight) {
			$y = max(0, $imageHeight - $height);
		}

		return [$x, $y, min($width, $imageWidth), min($height, $imageHeight)];
	}

	private function normalizeFaceBoxes(?array $data): array
	{
		if (!is_array($data) || !isset($data['faces']) || !is_array($data['faces'])) {
			return [];
		}

		$faces = [];
		foreach ($data['faces'] as $face) {
			if (!is_array($face)) {
				continue;
			}

			$x = $this->normalizeCoordinate($face['x'] ?? null);
			$y = $this->normalizeCoordinate($face['y'] ?? null);
			$width = $this->normalizeCoordinate($face['width'] ?? null);
			$height = $this->normalizeCoordinate($face['height'] ?? null);

			if ($x === null || $y === null || $width === null || $height === null || $width < 5 || $height < 5) {
				continue;
			}

			$faces[] = [
				'x' => $x,
				'y' => $y,
				'width' => min(1000 - $x, $width),
				'height' => min(1000 - $y, $height),
				'confidence' => (string) ($face['confidence'] ?? ''),
			];
		}

		return $faces;
	}

	private function normalizeCoordinate(mixed $value): ?int
	{
		if (!is_numeric($value)) {
			return null;
		}

		return max(0, min(1000, (int) round((float) $value)));
	}

	private function extractJsonObject(string $content): ?array
	{
		$data = json_decode($content, true);
		if (is_array($data)) {
			return $data;
		}

		$matches = [];
		preg_match('/\\{.*\\}/s', $content, $matches);

		return isset($matches[0]) ? json_decode($matches[0], true) : null;
	}

	private function createPreviewAsset(Asset $originalAsset, string $tempPath): ?Asset
	{
		$previewAsset = new Asset();
		$previewAsset->tempFilePath = $tempPath;
		$previewAsset->filename = $this->getPreviewFilename($originalAsset);
		$previewAsset->newFolderId = $originalAsset->folderId;
		$previewAsset->volumeId = $originalAsset->volumeId;
		$previewAsset->uploaderId = $this->userId ?: $originalAsset->uploaderId;
		$previewAsset->avoidFilenameConflicts = true;
		$previewAsset->setScenario(Asset::SCENARIO_CREATE);

		ImageQualityChecker::$skipAssetQueue = true;
		try {
			$saved = Craft::$app->elements->saveElement($previewAsset);
		} finally {
			ImageQualityChecker::$skipAssetQueue = false;
		}

		return $saved ? $previewAsset : null;
	}

	private function isSupportedImageAsset(?Asset $asset): bool
	{
		return $asset instanceof Asset &&
			$asset->kind === 'image' &&
			in_array($asset->mimeType, ['image/jpeg', 'image/jpg', 'image/png'], true);
	}

	private function getTempReplacementPath(Asset $asset): string
	{
		$extension = pathinfo($asset->filename, PATHINFO_EXTENSION);
		$tempPath = tempnam(sys_get_temp_dir(), 'image-quality-checker-');

		if (!$extension) {
			return $tempPath;
		}

		@unlink($tempPath);

		return $tempPath . '.' . $extension;
	}

	private function getPreviewFilename(Asset $asset): string
	{
		$extension = pathinfo($asset->filename, PATHINFO_EXTENSION);
		$baseName = pathinfo($asset->filename, PATHINFO_FILENAME);
		$baseName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseName) ?: 'image';

		return $baseName . '-face-blur-preview-' . date('YmdHis') . ($extension ? '.' . $extension : '');
	}

	private function getFullAssetPath(Asset $asset): ?string
	{
		if (!$this->isSupportedImageAsset($asset)) {
			return null;
		}

		$fsPath = Craft::getAlias($asset->getFs()->path);
		if (!$fsPath) {
			return null;
		}

		return $fsPath . DIRECTORY_SEPARATOR . $asset->folderPath . $asset->filename;
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

		$statusPayload = array_merge([
			'status' => $status,
			'assetId' => $this->assetId,
			'token' => $this->token,
			'operation' => 'blurFaces',
			'progress' => $progress,
			'progressLabel' => $progressLabel,
		], $extra);

		Craft::$app->getCache()->set($this->getStatusCacheKey(), $statusPayload, 3600);
		Craft::$app->getCache()->set($this->getAssetStatusCacheKey(), $statusPayload, 3600);
	}

	private function isCanceled(): bool
	{
		$status = Craft::$app->getCache()->get($this->getStatusCacheKey());

		return is_array($status) && ($status['status'] ?? null) === 'canceled';
	}

	private function finishCanceled($queue): void
	{
		$this->updateStatus('canceled', 1, 'Canceled');
		$this->setProgress($queue, 1, 'Canceled');
	}

	private function getStatusCacheKey(): string
	{
		return 'image-quality-checker:article-image-enhancement:' . $this->token;
	}

	private function getAssetStatusCacheKey(): string
	{
		return 'image-quality-checker:article-image-enhancement-asset:' . $this->assetId;
	}

	private function resolveChatGptModel(ClientInterface $client, string $configuredModel, string $apiKey): string
	{
		if ($configuredModel !== Settings::MODEL_LATEST) {
			return $configuredModel;
		}

		try {
			$response = $client->get('https://api.openai.com/v1/models', [
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
				],
			]);
			$data = json_decode((string) $response->getBody(), true);
			$models = array_values(array_filter(
				array_map(static fn(array $model): ?string => $model['id'] ?? null, $data['data'] ?? []),
				static fn(?string $model): bool => $model !== null && Settings::isSupportedChatGptModel($model)
			));

			usort($models, [$this, 'compareChatGptModels']);

			if (!empty($models)) {
				return $models[0];
			}
		} catch (\Throwable $e) {
			Craft::warning('ImageQualityChecker: Could not resolve latest OpenAI model for face blur: ' . $e->getMessage(), __METHOD__);
		}

		return 'gpt-4o';
	}

	private function compareChatGptModels(string $modelA, string $modelB): int
	{
		return $this->modelSortScore($modelB) <=> $this->modelSortScore($modelA);
	}

	private function modelSortScore(string $model): int
	{
		if (preg_match('/^gpt-(\d+)(?:\.(\d+))?/', $model, $matches)) {
			$major = (int) $matches[1];
			$minor = (int) ($matches[2] ?? 0);
			$sizePenalty = str_contains($model, 'nano') ? 20 : (str_contains($model, 'mini') ? 10 : 0);

			return ($major * 1000) + ($minor * 10) - $sizePenalty;
		}

		if (str_starts_with($model, 'gpt-4o')) {
			return 4000;
		}

		return 0;
	}

	private function getRelatedEntryForAsset(int $assetId): ?Entry
	{
		$sourceId = (new Query())
			->select(['sourceId'])
			->from(Table::RELATIONS)
			->where(['targetId' => $assetId])
			->scalar();

		if (!$sourceId) {
			return null;
		}

		$element = Craft::$app->elements->getElementById((int) $sourceId, null, '*');
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
			->status(null)
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
				->status(null)
				->one();

			if ($owner instanceof Entry) {
				return $this->normalizeEntry($owner, $seenEntryIds);
			}
		}

		$canonicalId = $entry->canonicalId ?? null;
		if ($canonicalId && (int) $canonicalId !== (int) $entry->id) {
			$canonical = Entry::find()
				->id($canonicalId)
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
		$length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
		if ($length <= $limit) {
			return $title;
		}

		$sliceLength = max(0, $limit - 3);
		$slice = function_exists('mb_substr') ? mb_substr($title, 0, $sliceLength) : substr($title, 0, $sliceLength);

		return rtrim($slice) . '...';
	}

	protected function defaultDescription(): string
	{
		$title = $this->getRelatedEntryForAsset($this->assetId)?->title ?? null;
		$title = $title ? $this->truncateTitle($title) : null;

		return $title ? 'Blur faces in article image preview: ' . $title : 'Blur faces in article image preview';
	}
}
