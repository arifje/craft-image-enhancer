<?php

namespace arjanbrinkman\craftimageenhancer\jobs;

use arjanbrinkman\craftimageenhancer\helpers\AnalysisResultHelper;
use arjanbrinkman\craftimageenhancer\helpers\AssetHelper;
use arjanbrinkman\craftimageenhancer\helpers\ChatModelHelper;
use arjanbrinkman\craftimageenhancer\helpers\FaceBoxHelper;
use arjanbrinkman\craftimageenhancer\helpers\FileHelper;
use arjanbrinkman\craftimageenhancer\helpers\HttpHelper;
use arjanbrinkman\craftimageenhancer\helpers\ImageHelper;
use arjanbrinkman\craftimageenhancer\helpers\SlackHelper;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\models\Settings;
use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\queue\BaseJob;
use GuzzleHttp\ClientInterface;
use Imagick;
use yii\queue\RetryableJobInterface;

/**
 * Checks the quality of a newly uploaded image with ChatGPT, optionally enhances it and
 * notifies editors via Slack and/or email.
 *
 * Idempotent: the job records the file's size and modification date when queued and skips
 * when the file changed since, or when another run already claimed/processed that file.
 */
class AnalyzeImageJob extends BaseJob implements RetryableJobInterface
{
	/**
	 * Worst case: analysis (120s) + three face checks (3 x 120s) + image edit (300s) +
	 * notifications and local image work.
	 */
	private const TTR = 1200;
	private const PROCESSED_TTL = 2592000;
	private const SUPPORTED_MIME_TYPES = ['image/jpeg', 'image/jpg', 'image/png'];

	public int $assetId;
	public ?int $entryId = null;
	/** File size when the job was queued. */
	public ?int $assetSize = null;
	/** File modification timestamp when the job was queued. */
	public ?int $assetDateModified = null;

	private mixed $_queue = null;
	private ?string $_localPath = null;
	private ?string $_analysisPath = null;
	private ?string $_chatModel = null;

	public function init(): void
	{
		parent::init();

		// Record the file fingerprint at enqueue time when the producer did not pass it.
		if (isset($this->assetId) && $this->assetSize === null && $this->assetDateModified === null) {
			$asset = Craft::$app->getAssets()->getAssetById($this->assetId);
			if ($asset instanceof Asset) {
				[$this->assetSize, $this->assetDateModified] = self::getFileFingerprint($asset);
			}
		}
	}

	public function getTtr(): int
	{
		return self::TTR;
	}

	/**
	 * Never retried automatically: a retry would repeat paid provider calls and notifications.
	 */
	public function canRetry($attempt, $error): bool
	{
		return false;
	}

	/**
	 * Executes the image quality analysis job using ChatGPT.
	 *
	 * @param \craft\queue\QueueInterface $queue
	 */
	public function execute($queue): void
	{
		$this->_queue = $queue;

		try {
			$this->analyze();
		} finally {
			FileHelper::delete($this->_localPath, $this->_analysisPath);
			$this->_localPath = null;
			$this->_analysisPath = null;
			$this->_chatModel = null;
			$this->_queue = null;
		}
	}

	/**
	 * Returns the asset's file size and modification timestamp.
	 *
	 * @return array{0: ?int, 1: ?int}
	 */
	public static function getFileFingerprint(Asset $asset): array
	{
		return [
			$asset->size !== null ? (int) $asset->size : null,
			$asset->dateModified?->getTimestamp(),
		];
	}

	private function analyze(): void
	{
		$settings = ImageEnhancer::getInstance()->getSettings();
		$this->updateProgress(0.05, 'Loading asset');

		if (!ImageEnhancer::getInstance()->runtimeSettings->isQualityCheckEnabled()) {
			$this->debugLog($settings, 'Skipping analysis because the plugin is disabled', [
				'assetId' => $this->assetId,
			]);
			$this->updateProgress(1, 'Skipped: plugin disabled');
			return;
		}

		if ($settings->imageEnhancementMode === Settings::ENHANCEMENT_DISABLED) {
			$this->debugLog($settings, 'Skipping analysis because enhancement mode is disabled', [
				'assetId' => $this->assetId,
			]);
			$this->updateProgress(1, 'Skipped: enhancement disabled');
			return;
		}

		$this->debugLog($settings, 'Job started', [
			'assetId' => $this->assetId,
			'entryId' => $this->entryId,
			'process' => $this->getProcessOwnershipContext(),
			'enhancementMode' => $settings->imageEnhancementMode,
			'enhancementTrigger' => $settings->imageEnhancementTrigger,
			'enhancementAction' => $settings->imageEnhancementAction,
			'slackNotification' => $settings->slackNotification,
			'hasSlackWebhook' => $settings->getResolvedSlackWebhookUrl() !== '',
			'hasSlackBotToken' => $settings->getResolvedSlackBotToken() !== '',
			'slackChannel' => $settings->slackChannel,
			'threshold' => $settings->notificationThreshold,
		]);

		$asset = Craft::$app->getAssets()->getAssetById($this->assetId);
		if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
			$this->debugLog($settings, 'Skipping asset because it was not found or is not an image', [
				'assetId' => $this->assetId,
			]);
			Craft::info('ImageEnhancer/AnalyzeImageJob: Asset not found or not an image.', __METHOD__);
			$this->updateProgress(1, 'Skipped: asset not found');
			return;
		}

		$this->debugLog($settings, 'Loaded asset', [
			'assetId' => $asset->id,
			'filename' => $asset->filename,
			'kind' => $asset->kind,
			'mimeType' => $asset->mimeType,
		]);

		if (!in_array($asset->mimeType, self::SUPPORTED_MIME_TYPES, true)) {
			$this->debugLog($settings, 'Skipping asset because its file type is not supported', [
				'mimeType' => $asset->mimeType,
			]);
			$this->updateProgress(1, 'Skipped: unsupported file type');
			return;
		}

		if (!ImageEnhancer::getInstance()->isAssetInAnalysisScope($asset)) {
			$this->debugLog($settings, 'Skipping asset because its volume is not selected', [
				'allowedHandles' => $settings->allowedAssetFieldHandles,
			]);
			Craft::info('ImageEnhancer/AnalyzeImageJob: Asset volume is not selected for analysis; skipping.', __METHOD__);
			$this->updateProgress(1, 'Skipped: volume not selected');
			return;
		}

		if ($this->hasFileChangedSinceQueued($asset)) {
			$this->debugLog($settings, 'Skipping asset because its file changed after the job was queued', [
				'queuedSize' => $this->assetSize,
				'queuedDateModified' => $this->assetDateModified,
				'currentFingerprint' => self::getFileFingerprint($asset),
			]);
			Craft::info("ImageEnhancer/AnalyzeImageJob: File of asset {$asset->id} changed since it was queued; skipping.", __METHOD__);
			$this->updateProgress(1, 'Skipped: file changed since queued');
			return;
		}

		// Claim this exact file version. The claim expires with the TTR, so a crashed run can
		// be retried manually; a finished run keeps it for PROCESSED_TTL.
		$processedKey = $this->getProcessedCacheKey($asset);
		$cache = Craft::$app->getCache();
		if (!$cache->add($processedKey, 'running', self::TTR)) {
			$this->debugLog($settings, 'Skipping asset because this file version was already processed or is in progress');
			$this->updateProgress(1, 'Skipped: already processed');
			return;
		}

		try {
			$this->_localPath = FileHelper::copyAssetToTemp($asset);
		} catch (\Throwable $e) {
			$cache->delete($processedKey);
			$this->debugLog($settings, 'Skipping asset because its file could not be read', [
				'error' => get_class($e),
			]);
			Craft::warning("ImageEnhancer/AnalyzeImageJob: File could not be read for asset ID {$asset->id}", __METHOD__);
			$this->updateProgress(1, 'Skipped: file not found');
			return;
		}

		$localPath = $this->_localPath;
		$this->debugLog($settings, 'Copied asset file to a local temp file', [
			'localPath' => $localPath,
			'fileSize' => filesize($localPath),
			'ownership' => $this->getFileOwnershipContext($localPath),
		]);

		$apiKey = $settings->getResolvedChatGptApiKey();
		$client = Craft::createGuzzleClient();
		$imageUrl = $asset->getUrl();
		$relatedEntry = $this->getRelatedEntryForAsset((int) $asset->id);
		$entryTitle = $relatedEntry?->title ?? null;
		$entryLink = $relatedEntry?->getCpEditUrl() ?? null;
		$author = $relatedEntry?->getAuthor()?->username
			?? ($asset->uploaderId ? Craft::$app->getUsers()->getUserById($asset->uploaderId)?->username : null)
			?? 'Onbekend';

		if ($settings->imageEnhancementTrigger === Settings::ENHANCEMENT_TRIGGER_ALWAYS) {
			$this->updateProgress(0.15, 'Skipping quality check');
			$this->debugLog($settings, 'Always-enhance trigger enabled; skipping quality analysis');
			$this->updateProgress(0.45, 'Starting enhancement');
			$data = [
				'scoreNum' => 'Niet gecontroleerd',
				'scoreEmoji' => '✨',
				'scoreLabel' => 'Altijd verbeteren',
				'author' => $author,
				'imageUrl' => $imageUrl,
				'entryLink' => $entryLink,
				'entryTitle' => $entryTitle,
				'reason' => 'Quality check skipped because always enhance is enabled.',
				'enhancement' => $this->enhanceImageIfEnabled($client, $settings, $asset, $localPath, $apiKey),
			];
			$cache->set($processedKey, 'done', self::PROCESSED_TTL);
			$this->updateProgress(0.80, 'Enhancement complete');
			$this->debugLog($settings, 'Enhancement result', $data['enhancement']);
			$this->updateProgress(0.90, 'Sending notifications');
			$this->sendSlackNotification($data);
			$this->sendEmailNotification($data);
			$this->updateProgress(1, 'Done');
			return;
		}

		if ($apiKey === '') {
			$cache->delete($processedKey);
			$this->debugLog($settings, 'Skipping analysis because OpenAI API key is missing');
			Craft::warning('AnalyzeImageJob: API key missing in settings.', __METHOD__);
			$this->updateProgress(1, 'Skipped: missing OpenAI API key');
			return;
		}

		$model = $this->getChatModel($settings);
		$this->updateProgress(0.15, 'Sending quality check');
		$this->debugLog($settings, 'Sending image to OpenAI for analysis', [
			'configuredModel' => $settings->chatGptModel,
			'resolvedModel' => $model,
			'reasoningEffort' => ChatModelHelper::getReasoningEffort($model),
		]);

		try {
			$json = ChatModelHelper::createCompletion($client, $apiKey, ChatModelHelper::buildPayload($model, [[
				'role' => 'user',
				'content' => [
					['type' => 'text', 'text' => $settings->chatGptPrompt . '. Return a JSON object without any other data, markup or styling. Example: {"score": X, "reason": "..."} where X is an integer from 0 to 100. Translate the value of reason to ' . $settings->chatGptResultLanguage . '.'],
					['type' => 'image_url', 'image_url' => ['url' => $this->getAnalysisDataUri()]],
				],
			]], 500));
		} catch (\Throwable $e) {
			$cache->delete($processedKey);
			$this->debugLog($settings, 'OpenAI analysis request failed', [
				'error' => HttpHelper::describeForUser($e),
				'resolvedModel' => $model,
			]);
			Craft::error('ImageEnhancer: OpenAI analysis request failed: ' . HttpHelper::describe($e) . ' ' . HttpHelper::describeForUser($e), __METHOD__);
			$this->updateProgress(1, 'Failed: quality check request failed');
			return;
		}

		$choice = $json['choices'][0] ?? [];
		$content = is_array($choice) && is_string($choice['message']['content'] ?? null) ? $choice['message']['content'] : '';
		if (trim($content) === '') {
			$cache->delete($processedKey);
			$this->debugLog($settings, 'OpenAI analysis returned no message content', [
				'finishReason' => is_array($choice) ? ($choice['finish_reason'] ?? null) : null,
				'responseKeys' => array_keys($json),
			]);
			Craft::error('AnalyzeImageJob: No response from ChatGPT.', __METHOD__);
			$this->updateProgress(1, 'Failed: no quality check response');
			return;
		}

		$result = AnalysisResultHelper::parse($content);
		$score = $result['score'];
		$band = AnalysisResultHelper::getScoreBand($score);
		$belowThreshold = AnalysisResultHelper::isBelowThreshold($score, $settings->notificationThreshold);
		$cache->set($processedKey, 'done', self::PROCESSED_TTL);
		$this->debugLog($settings, 'Parsed OpenAI analysis result', [
			'score' => $score,
			'validResponse' => $result['valid'],
			'threshold' => $settings->notificationThreshold,
			'willNotify' => $belowThreshold,
			'reasonPreview' => mb_substr($result['reason'], 0, 160),
		]);
		$this->updateProgress(0.35, 'Quality check complete');

		if (!$belowThreshold) {
			if ($score === null) {
				Craft::warning("ImageEnhancer/AnalyzeImageJob: Quality check for asset {$asset->id} returned no valid score; not enhancing.", __METHOD__);
			}
			$this->debugLog($settings, 'Score did not reach threshold or is unknown; no enhancement or notification sent', [
				'score' => $score,
				'threshold' => $settings->notificationThreshold,
			]);
			$this->updateProgress(1, $score === null ? 'Done: score unknown' : 'Done: score above threshold');
			return;
		}

		$data = [
			'scoreNum' => $score,
			'scoreEmoji' => $band['emoji'],
			'scoreLabel' => $band['label'],
			'author' => $author,
			'imageUrl' => $imageUrl,
			'entryLink' => $entryLink,
			'entryTitle' => $entryTitle,
			'reason' => $result['reason'],
		];

		$this->debugLog($settings, 'Score reached threshold; running enhancement and notifications', [
			'score' => $score,
			'threshold' => $settings->notificationThreshold,
		]);
		$this->updateProgress(0.45, 'Starting enhancement');
		$data['enhancement'] = $this->enhanceImageIfEnabled($client, $settings, $asset, $localPath, $apiKey);
		$this->updateProgress(0.80, 'Enhancement complete');
		$this->debugLog($settings, 'Enhancement result', $data['enhancement']);
		$this->updateProgress(0.90, 'Sending notifications');
		$this->sendSlackNotification($data);
		$this->sendEmailNotification($data);
		$this->updateProgress(1, 'Done');
	}

	private function enhanceImageIfEnabled(ClientInterface $client, Settings $settings, Asset $asset, string $localPath, string $apiKey): array
	{
		if ($settings->imageEnhancementMode === Settings::ENHANCEMENT_SAFE) {
			return $this->safeEnhanceImage($settings, $asset, $localPath);
		}

		if ($settings->imageEnhancementMode === Settings::ENHANCEMENT_CREATIVE) {
			return $this->creativeEnhanceImage($client, $settings, $asset, $localPath, $apiKey);
		}

		if ($settings->imageEnhancementMode === Settings::ENHANCEMENT_DISABLED) {
			$this->debugLog($settings, 'Enhancement disabled');
			return [
				'label' => 'Niet vervangen',
				'status' => 'Enhancement staat uit.',
			];
		}

		return $this->failedResult($asset, 'Niet vervangen', 'Onbekende enhancement mode.', 'Optimalisatie is mislukt: onbekende enhancement mode');
	}

	private function safeEnhanceImage(Settings $settings, Asset $asset, string $localPath): array
	{
		if (!class_exists(Imagick::class)) {
			$this->debugLog($settings, 'Imagick enhancement skipped because Imagick is not available');
			Craft::warning('ImageEnhancer: Imagick is required for safe image enhancement.', __METHOD__);

			return $this->failedResult($asset, 'Niet vervangen', 'Safe optimization mislukt: Imagick ontbreekt.', 'Optimalisatie is mislukt: Imagick ontbreekt');
		}

		$tempPath = null;
		try {
			$this->debugLog($settings, 'Starting Imagick enhancement', [
				'assetId' => $asset->id,
				'localPath' => $localPath,
				'originalFileSize' => filesize($localPath),
			]);
			$tempPath = FileHelper::createTempPathForAsset($asset);
			$image = new Imagick($localPath);
			$output = $image;
			try {
				ImageHelper::autoOrient($image);
				$originalWidth = $image->getImageWidth();
				$originalHeight = $image->getImageHeight();
				$image->enhanceImage();
				$image->unsharpMaskImage(0.7, 0.6, 1.0, 0.05);

				$scale = ImageHelper::getSafeEnhancementScale($originalWidth, $originalHeight, $settings->safeEnhancementMaxWidth);
				if ($scale > 1.0) {
					$image->resizeImage(
						max(1, (int) round($originalWidth * $scale)),
						max(1, (int) round($originalHeight * $scale)),
						Imagick::FILTER_LANCZOS,
						1,
					);
				}

				$output = ImageHelper::applyOutputFormat($image, (string) $asset->mimeType, $settings->safeEnhancementJpegQuality);
				// Drop EXIF/XMP (orientation is already applied) but keep the ICC color profile.
				ImageHelper::stripMetadataKeepingIcc($output);
				if (!$output->writeImage($tempPath)) {
					throw new \RuntimeException('Could not write the optimized image.');
				}
				$newWidth = $output->getImageWidth();
				$newHeight = $output->getImageHeight();
			} finally {
				if ($output !== $image) {
					$output->clear();
				}
				$image->clear();
			}

			$this->debugLog($settings, 'Imagick wrote replacement temp file', [
				'tempPath' => $tempPath,
				'tempFileSize' => is_file($tempPath) ? filesize($tempPath) : null,
				'originalWidth' => $originalWidth,
				'originalHeight' => $originalHeight,
				'newWidth' => $newWidth,
				'newHeight' => $newHeight,
				'scale' => $scale,
			]);

			return $this->storeEnhancedImage($settings, $asset, $tempPath, 'safe');
		} catch (\Throwable $e) {
			$this->debugLog($settings, 'Imagick enhancement failed', [
				'error' => $e->getMessage(),
			]);
			Craft::error('ImageEnhancer: Safe image enhancement failed: ' . $e->getMessage(), __METHOD__);

			return $this->failedResult($asset, 'Niet vervangen', 'Safe optimization mislukt.', 'Optimalisatie is mislukt');
		} finally {
			FileHelper::delete($tempPath);
		}
	}

	private function creativeEnhanceImage(ClientInterface $client, Settings $settings, Asset $asset, string $localPath, string $apiKey): array
	{
		if (
			$settings->imageEnhancementFaceHandling === Settings::FACE_HANDLING_SAFE_FALLBACK &&
			($apiKey === '' || $this->shouldUseSafeEnhancementForFaces($client, $settings, $apiKey))
		) {
			$this->debugLog($settings, 'Using safe enhancement instead of AI enhancement because a visible human face was detected, face detection failed, or OpenAI face detection is unavailable', [
				'assetId' => $asset->id,
			]);

			return $this->safeEnhanceImage($settings, $asset, $localPath);
		}

		$tempPath = null;
		try {
			[$originalWidth, $originalHeight] = ImageHelper::getOrientedSize($localPath) ?? [0, 0];
			$runtimeSettings = ImageEnhancer::getInstance()->runtimeSettings;
			$creativeEnhancementPrompt = $runtimeSettings->getCreativeEnhancementPromptForRequest($settings);
			$enhancementService = ImageEnhancer::getInstance()->aiImageEnhancement;
			$providerLabel = $enhancementService->getProviderLabel($settings);
			$this->debugLog($settings, 'Starting AI image enhancement', [
				'assetId' => $asset->id,
				'filename' => $asset->filename,
				'imageEnhancementProvider' => $settings->imageEnhancementProvider,
				'imageEnhancementProviderLabel' => $providerLabel,
				'imageEnhancementModel' => $enhancementService->getProviderModel($settings),
				'imageEnhancementFaceHandling' => $settings->imageEnhancementFaceHandling,
				'creativeEnhancementTuningLevels' => $settings->getCreativeEnhancementTuningLevels(),
				'creativeEnhancementPromptSource' => $runtimeSettings->hasCreativeEnhancementPromptOverride() ? 'runtime' : 'plugin-settings',
				'creativeEnhancementPromptLength' => strlen($creativeEnhancementPrompt),
				'creativeEnhancementPromptHash' => hash('sha256', $creativeEnhancementPrompt),
				'creativeEnhancementTuningPrompt' => $settings->getCreativeEnhancementTuningPrompt(),
				'fileSize' => filesize($localPath),
				'originalWidth' => $originalWidth,
				'originalHeight' => $originalHeight,
			]);

			$this->updateProgress(0.55, 'Sending image to ' . $providerLabel);
			$tempPath = $enhancementService->enhanceToTempFile($client, $settings, $asset, $localPath);
			$this->updateProgress(0.70, 'Validating enhanced image');

			$outputInfo = ImageHelper::getImageInfo($tempPath);
			$outputError = ImageHelper::validateImageInfo($outputInfo);
			if ($outputInfo === null || $outputError !== null) {
				throw new \RuntimeException('Provider output rejected: ' . $outputError . '.');
			}

			$plan = ImageHelper::planReplacement($originalWidth, $originalHeight, $outputInfo['width'], $outputInfo['height']);
			if ($plan === ImageHelper::PLAN_REPLACE) {
				// Same framing: restore the exact original size (crops at most a sliver).
				ImageHelper::cropToSize($tempPath, (string) $asset->mimeType, $originalWidth, $originalHeight);
			} else {
				// Different framing or much smaller: keep the provider's pixels (no crop, no
				// upscale), only convert the format. Replacing is refused in storeEnhancedImage().
				ImageHelper::fitWithin($tempPath, (string) $asset->mimeType, $outputInfo['width'], $outputInfo['height']);
			}

			$normalizedSize = ImageHelper::getImageInfo($tempPath);
			$this->debugLog($settings, 'AI image enhancement wrote temp file', [
				'tempPath' => $tempPath,
				'tempFileSize' => filesize($tempPath),
				'providerWidth' => $outputInfo['width'],
				'providerHeight' => $outputInfo['height'],
				'replacementPlan' => $plan,
				'normalizedWidth' => $normalizedSize['width'] ?? null,
				'normalizedHeight' => $normalizedSize['height'] ?? null,
			]);

			return $this->storeEnhancedImage($settings, $asset, $tempPath, 'ai', $plan === ImageHelper::PLAN_REPLACE ? null : $plan);
		} catch (\Throwable $e) {
			$this->debugLog($settings, 'AI image enhancement failed', [
				'error' => HttpHelper::describeForUser($e),
			]);
			Craft::error('ImageEnhancer: AI image enhancement failed: ' . HttpHelper::describe($e) . ' ' . HttpHelper::describeForUser($e), __METHOD__);

			return $this->failedResult($asset, 'Niet vervangen', 'AI enhancement mislukt.', 'Optimalisatie met AI is mislukt');
		} finally {
			FileHelper::delete($tempPath);
		}
	}

	private function shouldUseSafeEnhancementForFaces(ClientInterface $client, Settings $settings, string $apiKey): bool
	{
		try {
			$dataUri = $this->getAnalysisDataUri();
		} catch (\Throwable $e) {
			$this->debugLog($settings, 'Face detection skipped because the analysis copy could not be created', [
				'error' => get_class($e),
			]);

			return true;
		}

		$models = array_values(array_unique([
			$this->getChatModel($settings),
			'gpt-4o-mini',
			'gpt-4o',
		]));

		foreach ($models as $index => $model) {
			$this->updateProgress(0.46 + ($index * 0.03), 'Checking for faces');

			try {
				$json = ChatModelHelper::createCompletion($client, $apiKey, ChatModelHelper::buildPayload($model, [[
					'role' => 'user',
					'content' => [
						[
							'type' => 'text',
							'text' => 'Check whether this image contains any visible human face. Count blurry, motion-blurred, partially occluded, low-resolution, background, profile, or side-view faces as visible. Do not identify anyone. Return only valid JSON with this exact shape: {"visibleHumanFaces": true, "confidence": "high"}.',
						],
						[
							'type' => 'image_url',
							'image_url' => ['url' => $dataUri],
						],
					],
				]], 500, [
					'response_format' => ['type' => 'json_object'],
				]));
			} catch (\Throwable $e) {
				$this->debugLog($settings, 'Face detection attempt failed', [
					'error' => HttpHelper::describe($e),
					'model' => $model,
				]);

				continue;
			}

			$choice = $json['choices'][0] ?? [];
			$content = is_array($choice) && is_string($choice['message']['content'] ?? null) ? $choice['message']['content'] : '';
			$data = FaceBoxHelper::extractJsonObject($content);

			if (!is_array($data) || !is_bool($data['visibleHumanFaces'] ?? null)) {
				$this->debugLog($settings, 'Face detection returned an invalid response; retrying if possible', [
					'model' => $model,
					'finishReason' => is_array($choice) ? ($choice['finish_reason'] ?? null) : null,
					'responsePreview' => mb_substr($content, 0, 160),
				]);

				continue;
			}

			$this->debugLog($settings, 'Face detection result before AI enhancement', [
				'visibleHumanFaces' => $data['visibleHumanFaces'],
				'confidence' => $data['confidence'] ?? null,
				'model' => $model,
			]);

			return $data['visibleHumanFaces'];
		}

		$this->debugLog($settings, 'Face detection failed for all models; falling back to safe enhancement');

		return true;
	}

	/**
	 * Adds or replaces the asset with the enhanced temp file.
	 *
	 * Replacing is guarded: the file must be a valid image of the asset's type, and AI output
	 * whose framing or size does not fit the original ($replaceRefusedReason) is added as a new
	 * asset instead, so the original is never cropped or overwritten by a degraded version.
	 */
	private function storeEnhancedImage(Settings $settings, Asset $asset, string $tempPath, string $enhancementType, ?string $replaceRefusedReason = null): array
	{
		$isAi = $enhancementType === 'ai';
		$typeLabel = $isAi ? 'AI enhancement' : 'safe optimization';
		$slackSource = $isAi ? 'met AI' : 'veilig';

		$validationError = ImageHelper::validateImageInfo(ImageHelper::getImageInfo($tempPath), (string) $asset->mimeType);
		if ($validationError !== null) {
			$this->debugLog($settings, 'Enhanced image failed validation; original is kept', [
				'error' => $validationError,
			]);
			Craft::error("ImageEnhancer: Enhanced image for asset {$asset->id} failed validation ({$validationError}); the original was kept.", __METHOD__);

			return $this->failedResult($asset, 'Niet vervangen', ucfirst($typeLabel) . ' leverde geen geldige afbeelding op; het origineel is behouden.', 'Optimalisatie ' . $slackSource . ' leverde geen geldige afbeelding op');
		}

		$refusedReplace = $replaceRefusedReason !== null && $settings->imageEnhancementAction !== Settings::ENHANCEMENT_ACTION_ADD;
		if ($refusedReplace) {
			Craft::warning("ImageEnhancer: Not replacing asset {$asset->id} with AI output ({$replaceRefusedReason}); adding it as a new asset instead.", __METHOD__);
		}

		if ($refusedReplace || $settings->imageEnhancementAction === Settings::ENHANCEMENT_ACTION_ADD) {
			return $this->addEnhancedImage($settings, $asset, $tempPath, $typeLabel, $slackSource, $refusedReplace ? $replaceRefusedReason : null);
		}

		$originalUploaderId = $asset->uploaderId;
		try {
			ImageEnhancer::suppressAssetQueue(static function() use ($asset, $tempPath): void {
				Craft::$app->getAssets()->replaceAssetFile($asset, $tempPath, $asset->filename);
			});
		} catch (\Throwable $e) {
			Craft::error("ImageEnhancer: Replacing asset {$asset->id} failed: " . $e->getMessage(), __METHOD__);

			return $this->failedResult($asset, 'Niet vervangen', ucfirst($typeLabel) . ' kon het origineel niet vervangen.', 'Optimalisatie ' . $slackSource . ' is gelukt, maar vervangen is mislukt');
		}

		if ($asset->hasErrors()) {
			Craft::error("ImageEnhancer: Replacing asset {$asset->id} failed: " . json_encode($asset->getErrors()), __METHOD__);

			return $this->failedResult($asset, 'Niet vervangen', ucfirst($typeLabel) . ' kon het origineel niet vervangen.', 'Optimalisatie ' . $slackSource . ' is gelukt, maar vervangen is mislukt');
		}

		// replaceAssetFile() sets the uploader to the current user (none in a queue worker).
		$this->restoreUploader($asset, $originalUploaderId);

		$this->debugLog($settings, 'Enhanced image replacement completed', [
			'assetId' => $asset->id,
			'filename' => $asset->filename,
			'uploaderId' => $asset->uploaderId,
		]);

		return [
			'label' => 'Vervangen met ' . $typeLabel,
			'status' => 'Origineel bestand is vervangen door een geoptimaliseerde versie.',
			'action' => 'replace',
			'imageUrl' => $asset->getUrl(),
			'slackAction' => 'Afbeelding geoptimaliseerd ' . $slackSource . ' en vervangen',
		];
	}

	private function addEnhancedImage(Settings $settings, Asset $asset, string $tempPath, string $typeLabel, string $slackSource, ?string $replaceRefusedReason): array
	{
		try {
			$filename = $this->getEnhancedFilename($asset);
			$this->debugLog($settings, 'Adding enhanced image as a new asset', [
				'originalAssetId' => $asset->id,
				'folderId' => $asset->folderId,
				'filename' => $filename,
				'replaceRefusedReason' => $replaceRefusedReason,
			]);

			$enhancedAsset = AssetHelper::createAssetFromFile($asset, $tempPath, $filename, $asset->uploaderId);
			if (!$enhancedAsset instanceof Asset) {
				return $this->failedResult($asset, 'Niet toegevoegd', ucfirst($typeLabel) . ' is gemaakt, maar kon niet als asset worden toegevoegd.', 'Optimalisatie ' . $slackSource . ' is gelukt, maar toevoegen als extra asset is mislukt');
			}

			$attachedToField = $this->attachEnhancedAssetToOriginalField($settings, $asset, $enhancedAsset);
			$status = $attachedToField
				? 'Geoptimaliseerde afbeelding is toegevoegd naast het origineel.'
				: 'Geoptimaliseerde afbeelding is toegevoegd aan de asset folder, maar kon niet automatisch aan hetzelfde veld worden gekoppeld.';
			if ($replaceRefusedReason !== null) {
				$status = 'Het origineel is niet vervangen omdat ' . $this->describeReplaceRefusal($replaceRefusedReason) . '. ' . $status;
			}

			$this->debugLog($settings, 'Enhanced image added as new asset', [
				'originalAssetId' => $asset->id,
				'enhancedAssetId' => $enhancedAsset->id,
				'enhancedFilename' => $enhancedAsset->filename,
				'attachedToField' => $attachedToField,
			]);

			return [
				'label' => 'Toegevoegd met ' . $typeLabel,
				'status' => $status,
				'action' => 'add',
				'imageUrl' => $enhancedAsset->getUrl(),
				'slackAction' => 'Afbeelding geoptimaliseerd ' . $slackSource . ' en toegevoegd',
			];
		} catch (\Throwable $e) {
			$this->debugLog($settings, 'Adding enhanced image failed', [
				'error' => $e->getMessage(),
			]);
			Craft::error('ImageEnhancer: Adding enhanced image failed: ' . $e->getMessage(), __METHOD__);

			return $this->failedResult($asset, 'Niet toegevoegd', ucfirst($typeLabel) . ' kon niet als extra asset worden toegevoegd.', 'Optimalisatie ' . $slackSource . ' is mislukt; afbeelding is niet toegevoegd');
		}
	}

	private function describeReplaceRefusal(string $reason): string
	{
		return match ($reason) {
			ImageHelper::PLAN_TOO_SMALL => 'het resultaat veel kleiner is dan het origineel',
			default => 'de beeldverhouding van het resultaat afwijkt',
		};
	}

	private function restoreUploader(Asset $asset, ?int $uploaderId): void
	{
		if ($asset->uploaderId === $uploaderId) {
			return;
		}

		try {
			// A direct update avoids a second element save (and its events) for one column.
			Db::update(Table::ASSETS, ['uploaderId' => $uploaderId], ['id' => $asset->id], [], false);
			$asset->uploaderId = $uploaderId;
		} catch (\Throwable $e) {
			Craft::warning("ImageEnhancer: Could not restore the uploader of asset {$asset->id} (" . get_class($e) . ').', __METHOD__);
		}
	}

	private function failedResult(Asset $asset, string $label, string $status, string $slackAction): array
	{
		return [
			'label' => $label,
			'status' => $status,
			'action' => 'failed',
			'imageUrl' => $asset->getUrl(),
			'slackAction' => $slackAction,
		];
	}

	/**
	 * Adds the enhanced asset to the field that holds the original, on the canonical element.
	 * Skips (and logs) relations that belong to drafts or revisions.
	 */
	private function attachEnhancedAssetToOriginalField(Settings $settings, Asset $originalAsset, Asset $enhancedAsset): bool
	{
		$relations = (new Query())
			->select(['r.sourceId', 'r.fieldId', 'r.sourceSiteId', 'e.draftId'])
			->from(['r' => Table::RELATIONS])
			->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[r.sourceId]]')
			->where(['r.targetId' => $originalAsset->id])
			->andWhere(['not', ['r.fieldId' => null]])
			->andWhere(['e.revisionId' => null, 'e.dateDeleted' => null])
			->orderBy(['r.sortOrder' => SORT_ASC])
			->all();

		// Prefer a canonical source; draft-only relations are skipped below.
		$relation = null;
		foreach ($relations as $row) {
			if ($row['draftId'] === null) {
				$relation = $row;
				break;
			}
		}
		$relation ??= $relations[0] ?? null;

		if (!$relation) {
			$this->debugLog($settings, 'Could not attach enhanced asset because no original asset field relation was found', [
				'originalAssetId' => $originalAsset->id,
				'enhancedAssetId' => $enhancedAsset->id,
			]);
			return false;
		}

		$sourceSiteId = $relation['sourceSiteId'] !== null ? (int) $relation['sourceSiteId'] : null;
		$source = Craft::$app->getElements()->getElementById((int) $relation['sourceId'], null, $sourceSiteId ?? '*');
		$field = Craft::$app->getFields()->getFieldById((int) $relation['fieldId']);

		if (!$source || !$field) {
			$this->debugLog($settings, 'Could not attach enhanced asset because source element or field was missing', [
				'sourceId' => $relation['sourceId'],
				'fieldId' => $relation['fieldId'],
			]);
			return false;
		}

		if ($this->isDraftOrRevisionContent($source)) {
			$this->debugLog($settings, 'Not attaching enhanced asset because the original is only used in a draft or revision', [
				'sourceId' => $source->id,
			]);
			Craft::info("ImageEnhancer: Enhanced asset {$enhancedAsset->id} was not attached because element {$source->id} belongs to a draft or revision.", __METHOD__);
			return false;
		}

		$assetIds = (new Query())
			->select(['targetId'])
			->from(Table::RELATIONS)
			->where([
				'sourceId' => $relation['sourceId'],
				'fieldId' => $relation['fieldId'],
				'sourceSiteId' => $sourceSiteId,
			])
			->orderBy(['sortOrder' => SORT_ASC])
			->column();

		$assetIds = array_values(array_map('intval', $assetIds));

		if (!in_array((int) $enhancedAsset->id, $assetIds, true)) {
			$assetIds[] = (int) $enhancedAsset->id;
		}

		$source->setFieldValue($field->handle, $assetIds);
		$saved = Craft::$app->getElements()->saveElement($source, false);

		$this->debugLog($settings, 'Attached enhanced asset to original field', [
			'saved' => $saved,
			'sourceId' => $source->id,
			'sourceSiteId' => $sourceSiteId,
			'fieldHandle' => $field->handle,
			'assetIds' => $assetIds,
		]);

		return $saved;
	}

	/**
	 * Whether the element, or any element that owns it (nested entries, Matrix blocks), is a
	 * draft or revision.
	 */
	private function isDraftOrRevisionContent(ElementInterface $element): bool
	{
		$current = $element;
		for ($depth = 0; $depth < 5; $depth++) {
			if ($current->getIsDraft() || $current->getIsRevision()) {
				return true;
			}

			$ownerId = $current->ownerId ?? null;
			if (!$ownerId) {
				return false;
			}

			$owner = Craft::$app->getElements()->getElementById((int) $ownerId, null, $current->siteId);
			if (!$owner) {
				return false;
			}
			$current = $owner;
		}

		return false;
	}

	private function getEnhancedFilename(Asset $asset): string
	{
		$extension = FileHelper::sanitizeExtension((string) pathinfo((string) $asset->filename, PATHINFO_EXTENSION));

		return AssetHelper::getSafeBaseName($asset) . '-enhanced' . ($extension !== '' ? '.' . $extension : '');
	}

	private function hasFileChangedSinceQueued(Asset $asset): bool
	{
		[$size, $dateModified] = self::getFileFingerprint($asset);

		return ($this->assetSize !== null && $size !== $this->assetSize) ||
			($this->assetDateModified !== null && $dateModified !== $this->assetDateModified);
	}

	private function getProcessedCacheKey(Asset $asset): string
	{
		[$size, $dateModified] = self::getFileFingerprint($asset);

		return 'image-enhancer:analyzed:' . $asset->id . ':' . ($size ?? '-') . ':' . ($dateModified ?? '-');
	}

	private function getChatModel(Settings $settings): string
	{
		return $this->_chatModel ??= ChatModelHelper::resolveModel($settings);
	}

	/**
	 * Returns a data URI of a downscaled JPEG copy, created once per run.
	 *
	 * @throws \RuntimeException
	 */
	private function getAnalysisDataUri(): string
	{
		if ($this->_localPath === null) {
			throw new \RuntimeException('No local copy of the asset is available.');
		}

		$this->_analysisPath ??= ImageHelper::createAnalysisCopy($this->_localPath);
		$contents = file_get_contents($this->_analysisPath);
		if ($contents === false) {
			throw new \RuntimeException('Could not read the analysis copy.');
		}

		return 'data:image/jpeg;base64,' . base64_encode($contents);
	}

	private function getProcessOwnershipContext(): array
	{
		$uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
		$gid = function_exists('posix_getegid') ? posix_getegid() : null;

		return [
			'currentUser' => get_current_user(),
			'uid' => $uid,
			'user' => $this->getUserName($uid ?: null),
			'gid' => $gid,
			'group' => $gid !== null ? $this->getGroupName($gid) : null,
			'tmpDir' => sys_get_temp_dir(),
		];
	}

	private function getFileOwnershipContext(?string $path): array
	{
		if (!$path || !file_exists($path)) {
			return [
				'path' => $path,
				'exists' => false,
			];
		}

		$ownerId = @fileowner($path);
		$groupId = @filegroup($path);
		$perms = @fileperms($path);

		return [
			'path' => $path,
			'exists' => true,
			'ownerId' => $ownerId,
			'owner' => is_int($ownerId) ? $this->getUserName($ownerId) : null,
			'groupId' => $groupId,
			'group' => is_int($groupId) ? $this->getGroupName($groupId) : null,
			'permissions' => is_int($perms) ? substr(sprintf('%o', $perms), -4) : null,
			'isWritable' => is_writable($path),
		];
	}

	private function getUserName(?int $uid): ?string
	{
		if ($uid === null || !function_exists('posix_getpwuid')) {
			return null;
		}

		$user = posix_getpwuid($uid);

		return $user['name'] ?? null;
	}

	private function getGroupName(?int $gid): ?string
	{
		if ($gid === null || !function_exists('posix_getgrgid')) {
			return null;
		}

		$group = posix_getgrgid($gid);

		return $group['name'] ?? null;
	}

	private function debugLog(Settings $settings, string $message, array $context = []): void
	{
		if (!$settings->debugLogging) {
			return;
		}

		$line = 'ImageEnhancer DEBUG: ' . $message;

		if (!empty($context)) {
			$line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		Craft::info($line, __METHOD__);
	}

	private function updateProgress(float $progress, string $label): void
	{
		if ($this->_queue !== null) {
			$this->setProgress($this->_queue, $progress, $label);
		}
	}

	/**
	 * Sends a Slack message with the image quality analysis results.
	 *
	 * @param array $data The formatted result data from the ChatGPT analysis.
	 */
	private function sendSlackNotification(array $data): void
	{
		$settings = ImageEnhancer::getInstance()->getSettings();

		if (!$settings->slackNotification) {
			$this->debugLog($settings, 'Slack notification skipped because Slack notifications are disabled');
			return;
		}

		$sent = SlackHelper::send($settings, [
			'text' => 'Beeldkwaliteit analyse',
			'blocks' => [
				[
					'type' => 'section',
					'text' => [
						'type' => 'mrkdwn',
						'text' => $this->getSlackSummaryText($data),
					],
				],
			],
			'unfurl_links' => false,
			'unfurl_media' => true,
		], $settings->slackChannel);

		$this->debugLog($settings, 'Slack notification send attempted', [
			'sent' => $sent,
		]);
	}

	/**
	 * Sends an HTML email with image quality analysis results to the author.
	 * CCs the configured recipient if set in plugin settings.
	 *
	 * @param array $data The formatted result data from the ChatGPT analysis.
	 */
	private function sendEmailNotification(array $data): void
	{
		$settings = ImageEnhancer::getInstance()->getSettings();

		if (!$settings->emailNotification) {
			$this->debugLog($settings, 'Email notification skipped because email notifications are disabled');
			return;
		}

		$author = $data['author'] ?? null;
		$authorUser = $author ? Craft::$app->getUsers()->getUserByUsernameOrEmail($author) : null;
		$authorEmail = $authorUser?->email ?? null;

		if (!$authorEmail) {
			$this->debugLog($settings, 'Email notification skipped because no author email could be resolved', [
				'author' => $author,
			]);
			Craft::warning('ImageEnhancer: Auteur heeft geen geldig e-mailadres, e-mail wordt niet verzonden.', __METHOD__);
			return;
		}

		try {
			$mail = Craft::$app->getMailer()->compose()
				->setTo($authorEmail)
				->setSubject('Beeldkwaliteit analyse')
				->setHtmlBody($this->getEmailHtml($data));

			if (!empty($settings->emailNotificationRecipient)) {
				$mail->setCc($settings->emailNotificationRecipient);
			}

			$sent = $mail->send();
		} catch (\Throwable $e) {
			$sent = false;
			Craft::error('ImageEnhancer: Email notification failed (' . get_class($e) . ').', __METHOD__);
		}

		$this->debugLog($settings, 'Email notification send attempted', [
			'authorEmail' => $authorEmail,
			'cc' => $settings->emailNotificationRecipient,
			'sent' => $sent,
		]);
	}

	/**
	 * Builds the notification email. Every interpolated value is HTML-encoded and only
	 * http(s) URLs are linked.
	 */
	private function getEmailHtml(array $data): string
	{
		$score = $data['scoreNum'] ?? null;
		$scoreText = is_int($score) ? $score . '/100' : (string) $score;
		$details = [
			'<strong>Score:</strong> ' . Html::encode(trim(($data['scoreEmoji'] ?? '') . ' ' . $scoreText . ' (' . ($data['scoreLabel'] ?? '') . ')')),
			'<strong>Auteur:</strong> ' . Html::encode((string) ($data['author'] ?? '')),
		];

		if (isset($data['enhancement']['label'])) {
			$details[] = '<strong>Vervanging:</strong> ' . Html::encode((string) $data['enhancement']['label']);
		}

		$entryLink = (string) ($data['entryLink'] ?? '');
		if ($this->isHttpUrl($entryLink)) {
			$details[] = '<strong>Artikel:</strong> ' . Html::a(Html::encode((string) ($data['entryTitle'] ?: $entryLink)), $entryLink);
		}

		$html = '<h2>📸 Beeldkwaliteit analyse</h2><p>' . implode('<br>', $details) . '</p>';

		$imageUrl = (string) ($data['imageUrl'] ?? '');
		if ($this->isHttpUrl($imageUrl)) {
			$image = Html::img($imageUrl, [
				'alt' => 'Geanalyseerde afbeelding',
				'style' => 'max-width:400px; height:auto; border:1px solid #ddd;',
			]);
			$html .= '<p><strong>Afbeelding:</strong><br>' . Html::a($image, $imageUrl, ['target' => '_blank', 'rel' => 'noopener']) . '</p>';
		}

		return $html . '<p><strong>Toelichting:</strong><br>' . nl2br(Html::encode((string) ($data['reason'] ?? ''))) . '</p>';
	}

	private function isHttpUrl(string $url): bool
	{
		return preg_match('#^https?://#i', $url) === 1;
	}

	private function getSlackSummaryText(array $data): string
	{
		$title = (string) ($data['entryTitle'] ?: 'onbekend artikel');
		$article = $data['entryLink'] ? SlackHelper::link((string) $data['entryLink'], $title) : SlackHelper::escape($title);
		$author = SlackHelper::escape((string) ($data['author'] ?? ''));
		$scoreText = $this->getSlackScoreText($data);
		$actionText = SlackHelper::escape((string) ($data['enhancement']['slackAction'] ?? 'Afbeelding controleren'));
		$imageLinks = $this->getSlackImageLinks($data);

		return "📸 Slechte afbeelding gedetecteerd in artikel: {$article} ({$author})\n{$scoreText}\n👉 {$actionText} ({$imageLinks})";
	}

	private function getSlackScoreText(array $data): string
	{
		$score = $data['scoreNum'] ?? null;
		$emoji = $data['scoreEmoji'] ?? AnalysisResultHelper::UNKNOWN_EMOJI;
		$label = SlackHelper::escape((string) ($data['scoreLabel'] ?? AnalysisResultHelper::UNKNOWN_LABEL));

		if (is_int($score)) {
			return "{$emoji} {$score}/100 ({$label})";
		}

		return "{$emoji} {$label}";
	}

	private function getSlackImageLinks(array $data): string
	{
		$originalUrl = $data['imageUrl'] ?? null;
		$enhancement = $data['enhancement'] ?? [];
		$enhancementAction = $enhancement['action'] ?? null;
		$enhancedUrl = in_array($enhancementAction, ['add', 'replace'], true) ? ($enhancement['imageUrl'] ?? null) : null;

		if ($enhancementAction === 'add' && $originalUrl && $enhancedUrl) {
			return 'Origineel: ' . SlackHelper::link($originalUrl, 'link') . ' - Geoptimaliseerd: ' . SlackHelper::link($enhancedUrl, 'link');
		}

		if ($enhancedUrl) {
			return 'Geoptimaliseerd: ' . SlackHelper::link($enhancedUrl, 'link');
		}

		if ($originalUrl) {
			return 'Origineel: ' . SlackHelper::link($originalUrl, 'link');
		}

		return 'links niet beschikbaar';
	}

	/**
	 * Attempts to find the parent entry related to the given asset ID.
	 *
	 * @param int $assetId The asset ID to search a parent entry for.
	 * @return Entry|null The related entry if found.
	 */
	private function getRelatedEntryForAsset(int $assetId): ?Entry
	{
		if ($this->entryId) {
			$entry = Entry::find()
				->id($this->entryId)
				->site('*')
				->status(null)
				->one();

			if ($entry instanceof Entry) {
				return $this->normalizeEntryForNotification($entry);
			}
		}

		return $this->getParentEntryForAsset($assetId);
	}

	private function getParentEntryForAsset(int $assetId): ?Entry
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
			return $this->normalizeEntryForNotification($element);
		}

		$ownerId = $element->ownerId ?? null;

		if ($ownerId) {
			$owner = Entry::find()
				->id($ownerId)
				->site('*')
				->status(null)
				->one();

			return $owner instanceof Entry ? $this->normalizeEntryForNotification($owner) : null;
		}

		return null;
	}

	/**
	 * Resolves nested entries to their owner and drafts to their canonical entry.
	 */
	private function normalizeEntryForNotification(Entry $entry, array $seenEntryIds = []): Entry
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
				return $this->normalizeEntryForNotification($owner, $seenEntryIds);
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
				return $this->normalizeEntryForNotification($canonical, $seenEntryIds);
			}
		}

		return $entry;
	}

	/**
	 * Returns the default description for this job.
	 */
	protected function defaultDescription(): string
	{
		return 'Analyse image quality with ChatGPT';
	}
}
