<?php

namespace arjanbrinkman\craftimageenhancer\jobs;

use arjanbrinkman\craftimageenhancer\helpers\AssetHelper;
use arjanbrinkman\craftimageenhancer\helpers\FileHelper;
use arjanbrinkman\craftimageenhancer\helpers\HttpHelper;
use arjanbrinkman\craftimageenhancer\helpers\ImageHelper;
use arjanbrinkman\craftimageenhancer\helpers\JobStatus;
use arjanbrinkman\craftimageenhancer\helpers\SlackHelper;
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
use yii\queue\RetryableJobInterface;

/**
 * Creates an enhanced (or custom-edited) preview asset for the CP article image tools.
 *
 * Transient provider failures (connection errors, 429, 5xx) can be retried once by pushing a
 * new job, honouring Retry-After. Expected failures set the status to "failed" and return;
 * only programming errors are rethrown.
 */
class ArticleImageEnhancementJob extends BaseJob implements RetryableJobInterface
{
	/** Image edit request (300s) + result download + local normalization. */
	private const TTR = 600;
	private const MAX_RETRY_ATTEMPTS = 1;
	private const MAX_RETRY_DELAY = 3600;

	public int $assetId;
	public ?int $userId = null;
	public string $token;
	public int $retryAttempt = 0;
	public ?string $imageEnhancementProvider = null;
	public ?string $imageEnhancementModel = null;
	public ?string $customPrompt = null;
	public ?int $targetWidth = null;
	public ?int $targetHeight = null;

	public function getTtr(): int
	{
		return self::TTR;
	}

	/**
	 * Retries are pushed explicitly (see queueRetryIfEnabled()), never by the queue.
	 */
	public function canRetry($attempt, $error): bool
	{
		return false;
	}

	public function execute($queue): void
	{
		$settings = ImageEnhancer::getInstance()->getSettings();
		$localPath = null;
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

			$localPath = FileHelper::copyAssetToTemp($asset);

			$providerOptions = $this->getProviderOptions();
			$providerLabel = ImageEnhancer::getInstance()->aiImageEnhancement->getProviderLabel($settings, $providerOptions);
			$progressLabel = $this->isCustomEnhancement() ? 'Applying custom edit with ' . $providerLabel : 'Sending image to ' . $providerLabel;
			$this->updateStatus('running', 0.2, $progressLabel);
			$this->setProgress($queue, 0.2, $progressLabel);
			$tempPath = $this->enhanceToTempFile(Craft::createGuzzleClient(), $settings, $asset, $localPath, $providerOptions);

			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$previewProgressLabel = $this->isCustomEnhancement() ? 'Saving custom edit preview' : 'Saving enhanced preview';
			$this->updateStatus('running', 0.85, $previewProgressLabel);
			$this->setProgress($queue, 0.85, $previewProgressLabel);
			$previewAsset = AssetHelper::createAssetFromFile(
				$asset,
				$tempPath,
				AssetHelper::getPreviewFilename($asset, AssetHelper::ENHANCEMENT_PREVIEW_MARKER),
				$this->userId ?: $asset->uploaderId,
			);

			if (!$previewAsset instanceof Asset) {
				throw new \RuntimeException('Could not save the enhanced preview asset.');
			}

			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$completeLabel = $this->isCustomEnhancement() ? 'Custom edit preview ready' : 'Enhanced preview ready';
			$this->updateStatus('complete', 1, $completeLabel, [
				'previewId' => $previewAsset->id,
			]);

			// A cancel that raced the "complete" write must not leave an orphaned preview.
			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$previewAsset = null;
			$this->setProgress($queue, 1, $completeLabel);
		} catch (\Throwable $e) {
			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			if ($this->queueRetryIfEnabled($settings, $e)) {
				Craft::warning('ImageEnhancer: Article image enhancement failed; retry queued: ' . HttpHelper::describe($e), __METHOD__);
				$this->setProgress($queue, 1, 'Retry queued');
				return;
			}

			$failedLabel = $this->isCustomEnhancement() ? 'Custom edit failed' : 'Enhancement failed';
			$this->updateStatus('failed', 1, $failedLabel, [
				'message' => HttpHelper::describeForUser($e),
			]);
			$this->setProgress($queue, 1, $failedLabel);
			$this->sendSlackErrorNotification($settings, $e);
			Craft::error('ImageEnhancer: Article image enhancement queue job failed: ' . HttpHelper::describe($e) . ' ' . HttpHelper::describeForUser($e), __METHOD__);

			// Programming errors should stay visible as failed queue jobs.
			if ($e instanceof \Error) {
				throw $e;
			}
		} finally {
			// Set to null once the preview is handed over; otherwise it is orphaned.
			if ($previewAsset instanceof Asset) {
				AssetHelper::deleteGeneratedAsset($previewAsset);
			}
			FileHelper::delete($localPath, $tempPath);
		}
	}

	/**
	 * @throws \RuntimeException
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	private function enhanceToTempFile(ClientInterface $client, Settings $settings, Asset $asset, string $localPath, array $providerOptions = []): string
	{
		[$originalWidth, $originalHeight] = ImageHelper::getOrientedSize($localPath) ?? [0, 0];
		$tempPath = ImageEnhancer::getInstance()->aiImageEnhancement->enhanceToTempFile(
			$client,
			$settings,
			$asset,
			$localPath,
			$providerOptions,
			$this->customPrompt,
		);

		try {
			$error = ImageHelper::validateImageInfo(ImageHelper::getImageInfo($tempPath));
			if ($error !== null) {
				throw new \RuntimeException('The provider returned an unusable image (' . $error . ').');
			}

			$targetWidth = $this->targetWidth ?: $originalWidth;
			$targetHeight = $this->targetHeight ?: $originalHeight;
			if ($targetWidth > 0 && $targetHeight > 0) {
				// The editor reviews this preview before keeping it, so filling the target size is fine.
				ImageHelper::cropToSize($tempPath, (string) $asset->mimeType, $targetWidth, $targetHeight);
			} else {
				ImageHelper::fitWithin($tempPath, (string) $asset->mimeType, ImageHelper::MAX_DIMENSION, ImageHelper::MAX_DIMENSION);
			}
		} catch (\Throwable $e) {
			FileHelper::delete($tempPath);
			throw $e;
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

	private function isCustomEnhancement(): bool
	{
		return trim((string) $this->customPrompt) !== '';
	}

	private function isSupportedImageAsset(Asset $asset): bool
	{
		return $asset->kind === Asset::KIND_IMAGE &&
			in_array($asset->mimeType, ['image/jpeg', 'image/jpg', 'image/png'], true);
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
			'operation' => $this->isCustomEnhancement() ? 'customEnhance' : 'enhance',
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

	/**
	 * Pushes one retry for transient failures. Returns true when a retry was queued.
	 */
	private function queueRetryIfEnabled(Settings $settings, \Throwable $error): bool
	{
		if (
			!$settings->retryFailedEnhancementJobs ||
			$this->retryAttempt >= self::MAX_RETRY_ATTEMPTS ||
			!HttpHelper::isRetryable($error) ||
			$this->isCanceled()
		) {
			return false;
		}

		$delay = max(0, (int) $settings->failedEnhancementRetryDelay);
		$retryAfter = HttpHelper::getRetryAfterSeconds($error);
		if ($retryAfter !== null) {
			$delay = max($delay, min($retryAfter, self::MAX_RETRY_DELAY));
		}
		$nextAttempt = $this->retryAttempt + 1;

		try {
			$jobId = Queue::push(new self([
				'assetId' => $this->assetId,
				'userId' => $this->userId,
				'token' => $this->token,
				'retryAttempt' => $nextAttempt,
				'imageEnhancementProvider' => $this->imageEnhancementProvider,
				'imageEnhancementModel' => $this->imageEnhancementModel,
				'customPrompt' => $this->customPrompt,
				'targetWidth' => $this->targetWidth,
				'targetHeight' => $this->targetHeight,
			]), null, $delay);
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Could not queue retry for failed article image enhancement (' . get_class($e) . ').', __METHOD__);
			return false;
		}

		$this->updateStatus('queued', 0, $delay > 0 ? 'Retrying in ' . $delay . ' seconds' : 'Retrying', [
			'jobId' => $jobId,
			'retryAttempt' => $nextAttempt,
			'retryDelay' => $delay,
			'previousError' => 'The image provider was temporarily unavailable.',
		]);

		return true;
	}

	private function sendSlackErrorNotification(Settings $settings, \Throwable $error): void
	{
		if (!$settings->slackErrorNotification) {
			return;
		}

		$errorChannel = trim($settings->slackErrorChannel) ?: trim($settings->slackChannel);

		SlackHelper::send($settings, [
			'text' => 'Image enhancement error',
			'blocks' => [
				[
					'type' => 'section',
					'text' => [
						'type' => 'mrkdwn',
						'text' => $this->getSlackErrorText($error),
					],
				],
			],
			'unfurl_links' => false,
			'unfurl_media' => false,
		], $errorChannel, true);
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

	private function getSlackErrorText(\Throwable $error): string
	{
		$entry = $this->getRelatedEntryForAsset($this->assetId);
		$title = $entry?->title ?: 'onbekend artikel';
		$editUrl = $entry?->getCpEditUrl();
		$article = $editUrl ? SlackHelper::link($editUrl, $title) : SlackHelper::escape($title);
		$message = SlackHelper::escape($this->truncateText(HttpHelper::describeForUser($error), 700));

		return "⚠️ Image enhancement failed for article: {$article}\nAsset ID: {$this->assetId}\nError: {$message}";
	}

	private function truncateText(string $text, int $limit): string
	{
		$text = trim($text);
		if (mb_strlen($text) <= $limit) {
			return $text;
		}

		return rtrim(mb_substr($text, 0, max(0, $limit - 3))) . '...';
	}

	protected function defaultDescription(): string
	{
		$title = $this->getRelatedEntryForAsset($this->assetId)?->title ?? null;
		$title = $title ? $this->truncateText($title, 25) : null;

		$prefix = $this->isCustomEnhancement() ? 'Custom edit article image preview' : 'Enhance article image preview';

		return $title ? $prefix . ': ' . $title : $prefix;
	}
}
