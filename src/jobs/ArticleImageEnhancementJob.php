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

class ArticleImageEnhancementJob extends BaseJob
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

			$apiKey = $settings->chatGptApiKey;
			if (!$apiKey) {
				throw new \RuntimeException('OpenAI API key is missing.');
			}

			$localPath = $this->getFullAssetPath($asset);
			if (!$localPath || !file_exists($localPath)) {
				throw new \RuntimeException('Could not find the original asset file.');
			}

			$this->updateStatus('running', 0.2, 'Sending image to OpenAI');
			$this->setProgress($queue, 0.2, 'Sending image to OpenAI');
			$tempPath = $this->enhanceToTempFile(Craft::createGuzzleClient(), $settings, $asset, $localPath, $apiKey);

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
				'enhancedUrl' => $this->appendCacheBuster($previewAsset->getUrl()),
			]);
			$this->setProgress($queue, 1, 'Enhanced preview ready');
		} catch (\Throwable $e) {
			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$this->updateStatus('failed', 1, 'Enhancement failed', [
				'message' => $e->getMessage(),
			]);
			$this->sendSlackErrorNotification($settings, $e);
			Craft::error('ImageQualityChecker: Article image enhancement queue job failed: ' . $e->getMessage(), __METHOD__);
			throw $e;
		}
	}

	private function enhanceToTempFile(ClientInterface $client, Settings $settings, Asset $asset, string $localPath, string $apiKey): string
	{
		$handle = fopen($localPath, 'rb');
		if ($handle === false) {
			throw new \RuntimeException('Could not open the original asset file.');
		}

		try {
			[$originalWidth, $originalHeight] = getimagesize($localPath) ?: [null, null];
			$response = $client->post('https://api.openai.com/v1/images/edits', [
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
				],
				'multipart' => [
					[
						'name' => 'model',
						'contents' => $settings->imageEnhancementModel,
					],
					[
						'name' => 'image',
						'contents' => $handle,
						'filename' => $asset->filename,
					],
					[
						'name' => 'prompt',
						'contents' => $settings->getCreativeEnhancementPromptForRequest(),
					],
					[
						'name' => 'size',
						'contents' => 'auto',
					],
					[
						'name' => 'quality',
						'contents' => 'auto',
					],
					[
						'name' => 'output_format',
						'contents' => $asset->mimeType === 'image/png' ? 'png' : 'jpeg',
					],
				],
			]);
			$data = json_decode((string) $response->getBody(), true);
			$imageData = $data['data'][0]['b64_json'] ?? null;

			if (!$imageData) {
				throw new \RuntimeException('OpenAI returned no enhanced image data.');
			}

			$tempPath = $this->getTempReplacementPath($asset);
			file_put_contents($tempPath, base64_decode($imageData));

			if ($originalWidth && $originalHeight) {
				$this->normalizeReplacementImageDimensions($asset, $tempPath, $originalWidth, $originalHeight);
			}

			return $tempPath;
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
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

	private function deletePreviewAsset(Asset $asset): void
	{
		ImageQualityChecker::$skipAssetQueue = true;
		try {
			Craft::$app->elements->deleteElement($asset);
		} finally {
			ImageQualityChecker::$skipAssetQueue = false;
		}
	}

	private function normalizeReplacementImageDimensions(Asset $asset, string $path, int $targetWidth, int $targetHeight): void
	{
		if (!class_exists(Imagick::class)) {
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

		Craft::$app->getCache()->set($this->getStatusCacheKey(), array_merge([
			'status' => $status,
			'assetId' => $this->assetId,
			'progress' => $progress,
			'progressLabel' => $progressLabel,
		], $extra), 3600);
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

			if ($settings->slackWebhookUrl) {
				$client->post($settings->slackWebhookUrl, [
					'json' => [
						'text' => 'Image enhancement error',
						'blocks' => $blocks,
						'unfurl_links' => false,
						'unfurl_media' => false,
					],
				]);
				return;
			}

			if (!$settings->slackBotToken || !$settings->slackChannel) {
				Craft::warning('ImageQualityChecker: Slack error notification skipped because bot token or channel is missing.', __METHOD__);
				return;
			}

			$response = $client->post('https://slack.com/api/chat.postMessage', [
				'headers' => [
					'Authorization' => 'Bearer ' . $settings->slackBotToken,
					'Content-Type' => 'application/json',
				],
				'json' => [
					'channel' => $settings->slackChannel,
					'text' => 'Image enhancement error',
					'blocks' => $blocks,
					'unfurl_links' => false,
					'unfurl_media' => false,
				],
			]);
			$responseData = json_decode((string) $response->getBody(), true);
			if (($responseData['ok'] ?? true) === false) {
				Craft::warning('ImageQualityChecker: Slack error notification API error: ' . ($responseData['error'] ?? 'unknown'), __METHOD__);
			}
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Slack error notification failed: ' . $e->getMessage(), __METHOD__);
		}
	}

	private function getStatusCacheKey(): string
	{
		return 'image-quality-checker:article-image-enhancement:' . $this->token;
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
