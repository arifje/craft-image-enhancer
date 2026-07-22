<?php

namespace arjanbrinkman\craftimageenhancer\jobs;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\models\Settings;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use GuzzleHttp\ClientInterface;
use Imagick;

class ArticleImageEnhancementJob extends BaseJob
{
	public int $assetId;
	public ?int $userId = null;
	public string $token;
	public int $retryAttempt = 0;
	public ?string $imageEnhancementProvider = null;
	public ?string $imageEnhancementModel = null;
	public ?int $targetWidth = null;
	public ?int $targetHeight = null;

	public function execute($queue): void
	{
		$settings = ImageEnhancer::getInstance()->getSettings();
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

			$localPath = $this->getFullAssetPath($asset);
			if (!$localPath || !file_exists($localPath)) {
				throw new \RuntimeException('Could not find the original asset file.');
			}

			$providerOptions = $this->getProviderOptions();
			$providerLabel = ImageEnhancer::getInstance()->aiImageEnhancement->getProviderLabel($settings, $providerOptions);
			$this->updateStatus('running', 0.2, 'Sending image to ' . $providerLabel);
			$this->setProgress($queue, 0.2, 'Sending image to ' . $providerLabel);
			$tempPath = $this->enhanceToTempFile(Craft::createGuzzleClient(), $settings, $asset, $localPath, $providerOptions);

			if ($this->isCanceled()) {
				@unlink($tempPath);
				$this->finishCanceled($queue);
				return;
			}

			$this->updateStatus('running', 0.85, 'Saving enhanced preview');
			$this->setProgress($queue, 0.85, 'Saving enhanced preview');
			$previewAsset = $this->createPreviewAsset($asset, $tempPath);

			if (!$previewAsset instanceof Asset) {
				@unlink($tempPath);
				throw new \RuntimeException('Could not save the enhanced preview asset.');
			}

			if ($this->isCanceled()) {
				$this->deletePreviewAsset($previewAsset);
				$this->finishCanceled($queue);
				return;
			}

			$this->updateStatus('complete', 1, 'Enhanced preview ready', [
				'previewId' => $previewAsset->id,
			]);
			$this->setProgress($queue, 1, 'Enhanced preview ready');
		} catch (\Throwable $e) {
			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			if ($this->queueRetryIfEnabled($settings, $e)) {
				Craft::warning('ImageEnhancer: Article image enhancement failed; retry queued: ' . $e->getMessage(), __METHOD__);
				throw $e;
			}

			$this->updateStatus('failed', 1, 'Enhancement failed', [
				'message' => $e->getMessage(),
			]);
			$this->sendSlackErrorNotification($settings, $e);
			Craft::error('ImageEnhancer: Article image enhancement queue job failed: ' . $e->getMessage(), __METHOD__);
			throw $e;
		}
	}

	private function enhanceToTempFile(ClientInterface $client, Settings $settings, Asset $asset, string $localPath, array $providerOptions = []): string
	{
		[$originalWidth, $originalHeight] = getimagesize($localPath) ?: [null, null];
		$tempPath = ImageEnhancer::getInstance()->aiImageEnhancement->enhanceToTempFile($client, $settings, $asset, $localPath, $providerOptions);

		$targetWidth = $this->targetWidth ?: $originalWidth;
		$targetHeight = $this->targetHeight ?: $originalHeight;
		if ($targetWidth && $targetHeight) {
			$this->normalizeReplacementImageDimensions($asset, $tempPath, $targetWidth, $targetHeight);
		}

		return $tempPath;
	}

	private function getProviderOptions(): array
	{
		if (!$this->imageEnhancementProvider || !$this->imageEnhancementModel) {
			return [];
		}

		return [
			'provider' => $this->imageEnhancementProvider,
			'model' => $this->imageEnhancementModel,
		];
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

		ImageEnhancer::$skipAssetQueue = true;
		try {
			$saved = Craft::$app->elements->saveElement($previewAsset);
		} finally {
			ImageEnhancer::$skipAssetQueue = false;
		}

		return $saved ? $previewAsset : null;
	}

	private function deletePreviewAsset(Asset $asset): void
	{
		ImageEnhancer::$skipAssetQueue = true;
		try {
			Craft::$app->elements->deleteElement($asset);
		} finally {
			ImageEnhancer::$skipAssetQueue = false;
		}
	}

	private function normalizeReplacementImageDimensions(Asset $asset, string $path, int $targetWidth, int $targetHeight): void
	{
		if (!class_exists(Imagick::class)) {
			$normalizedPath = $this->getTempReplacementPath($asset);
			try {
				$image = Craft::$app->getImages()->loadImage($path);
				$image->scaleAndCrop($targetWidth, $targetHeight, true);
				if (!$image->saveAs($normalizedPath) || !copy($normalizedPath, $path)) {
					throw new \RuntimeException('Could not normalize the enhanced image dimensions.');
				}
			} finally {
				if (file_exists($normalizedPath)) {
					@unlink($normalizedPath);
				}
			}

			return;
		}

		$image = new Imagick($path);
		$image->setImageGravity(Imagick::GRAVITY_CENTER);
		$image->cropThumbnailImage($targetWidth, $targetHeight);

		if (in_array($asset->mimeType, ['image/jpeg', 'image/jpg'], true)) {
			$image->setImageCompression(Imagick::COMPRESSION_JPEG);
			$image->setImageCompressionQuality(90);
			$image->setImageFormat('jpeg');
		} elseif ($asset->mimeType === 'image/png') {
			$image->setImageFormat('png');
		}

		$image->writeImage($path);
		$image->clear();
		$image->destroy();
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
		$tempPath = tempnam(sys_get_temp_dir(), 'image-enhancer-');
		if ($tempPath === false) {
			throw new \RuntimeException('Could not create a temporary image file.');
		}

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

		return $baseName . '-enhancement-preview-' . date('YmdHis') . ($extension ? '.' . $extension : '');
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

	private function updateStatus(string $status, float $progress, string $progressLabel, array $extra = []): void
	{
		if ($status !== 'canceled' && $this->isCanceled()) {
			return;
		}

		$statusPayload = array_merge([
			'status' => $status,
			'assetId' => $this->assetId,
			'token' => $this->token,
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

	private function queueRetryIfEnabled(Settings $settings, \Throwable $error): bool
	{
		if (!$settings->retryFailedEnhancementJobs || $this->retryAttempt >= 1 || $this->isCanceled()) {
			return false;
		}

		$delay = max(0, (int) $settings->failedEnhancementRetryDelay);
		$nextAttempt = $this->retryAttempt + 1;

		try {
			$jobId = Queue::push(new self([
				'assetId' => $this->assetId,
				'userId' => $this->userId,
				'token' => $this->token,
				'retryAttempt' => $nextAttempt,
				'imageEnhancementProvider' => $this->imageEnhancementProvider,
				'imageEnhancementModel' => $this->imageEnhancementModel,
				'targetWidth' => $this->targetWidth,
				'targetHeight' => $this->targetHeight,
			]), null, $delay);

			$this->updateStatus('queued', 0, $delay > 0 ? 'Retrying in ' . $delay . ' seconds' : 'Retrying', [
				'jobId' => $jobId,
				'retryAttempt' => $nextAttempt,
				'retryDelay' => $delay,
				'previousError' => $error->getMessage(),
			]);

			return true;
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Could not queue retry for failed article image enhancement: ' . $e->getMessage(), __METHOD__);
			return false;
		}
	}

	private function sendSlackErrorNotification(Settings $settings, \Throwable $error): void
	{
		if (!$settings->slackErrorNotification) {
			return;
		}

		$blocks = [
			[
				'type' => 'section',
				'text' => [
					'type' => 'mrkdwn',
					'text' => $this->getSlackErrorText($error),
				],
			],
		];

		try {
			$client = Craft::createGuzzleClient();
			$errorChannel = trim($settings->slackErrorChannel) ?: trim($settings->slackChannel);

			if ($settings->slackWebhookUrl) {
				$payload = [
					'text' => 'Image enhancement error',
					'blocks' => $blocks,
					'unfurl_links' => false,
					'unfurl_media' => false,
				];
				if ($errorChannel !== '') {
					$payload['channel'] = $errorChannel;
				}

				$client->post($settings->slackWebhookUrl, [
					'json' => $payload,
				]);
				return;
			}

			if (!$settings->slackBotToken || $errorChannel === '') {
				Craft::warning('ImageEnhancer: Slack error notification skipped because bot token or channel is missing.', __METHOD__);
				return;
			}

			$response = $client->post('https://slack.com/api/chat.postMessage', [
				'headers' => [
					'Authorization' => 'Bearer ' . $settings->slackBotToken,
					'Content-Type' => 'application/json',
				],
				'json' => [
					'channel' => $errorChannel,
					'text' => 'Image enhancement error',
					'blocks' => $blocks,
					'unfurl_links' => false,
					'unfurl_media' => false,
				],
			]);
			$responseData = json_decode((string) $response->getBody(), true);
			if (($responseData['ok'] ?? true) === false) {
				Craft::warning('ImageEnhancer: Slack error notification API error: ' . ($responseData['error'] ?? 'unknown'), __METHOD__);
			}
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Slack error notification failed: ' . $e->getMessage(), __METHOD__);
		}
	}

	private function getStatusCacheKey(): string
	{
		return 'image-enhancer:article-image-enhancement:' . $this->token;
	}

	private function getAssetStatusCacheKey(): string
	{
		return 'image-enhancer:article-image-enhancement-asset:' . $this->assetId;
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
		return $this->truncateText($title, $limit);
	}

	private function getSlackErrorText(\Throwable $error): string
	{
		$entry = $this->getRelatedEntryForAsset($this->assetId);
		$title = $entry?->title ?: 'onbekend artikel';
		$article = $entry?->getCpEditUrl()
			? $this->formatSlackLink($entry->getCpEditUrl(), $title)
			: $this->escapeSlackText($title);
		$message = $this->escapeSlackText($this->truncateText($error->getMessage(), 700));

		return "⚠️ Image enhancement failed for article: {$article}\nAsset ID: {$this->assetId}\nError: {$message}";
	}

	private function truncateText(string $text, int $limit): string
	{
		$text = trim($text);
		if ($text === '') {
			return '';
		}

		$length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
		if ($length <= $limit) {
			return $text;
		}

		$sliceLength = max(0, $limit - 3);
		$slice = function_exists('mb_substr') ? mb_substr($text, 0, $sliceLength) : substr($text, 0, $sliceLength);

		return rtrim($slice) . '...';
	}

	private function formatSlackLink(string $url, string $label): string
	{
		return '<' . str_replace('>', '%3E', $url) . '|' . $this->escapeSlackText($label) . '>';
	}

	private function escapeSlackText(string $text): string
	{
		return str_replace(
			['&', '<', '>', '|'],
			['&amp;', '&lt;', '&gt;', '/'],
			$text
		);
	}

	protected function defaultDescription(): string
	{
		$title = $this->getRelatedEntryForAsset($this->assetId)?->title ?? null;
		$title = $title ? $this->truncateTitle($title) : null;

		return $title ? 'Enhance article image preview: ' . $title : 'Enhance article image preview';
	}
}
