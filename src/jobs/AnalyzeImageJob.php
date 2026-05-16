<?php

namespace arjanbrinkman\craftimagequalitychecker\jobs;

use arjanbrinkman\craftimagequalitychecker\ImageQualityChecker;
use arjanbrinkman\craftimagequalitychecker\models\Settings;

use Craft;
use craft\queue\BaseJob;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\db\Query;
use craft\db\Table;
use GuzzleHttp\ClientInterface;
use Imagick;

class AnalyzeImageJob extends BaseJob
{
	public int $assetId;

	/**
	 * Executes the image quality analysis job using ChatGPT.
	 * Checks if the asset should be analyzed, sends it to ChatGPT,
	 * parses the result, and sends notifications via Slack and/or email.
	 *
	 * @param \craft\queue\QueueInterface $queue
	 */
	public function execute($queue): void
	{
		$settings = ImageQualityChecker::getInstance()->getSettings();
		$this->updateProgress($queue, 0.05, 'Loading asset');
		$this->debugLog($settings, 'Job started', [
			'assetId' => $this->assetId,
			'process' => $this->getProcessOwnershipContext(),
			'enhancementMode' => $settings->imageEnhancementMode,
			'enhancementTrigger' => $settings->imageEnhancementTrigger,
			'slackNotification' => $settings->slackNotification,
			'hasSlackWebhook' => (bool) $settings->slackWebhookUrl,
			'hasSlackBotToken' => (bool) $settings->slackBotToken,
			'slackChannel' => $settings->slackChannel,
			'threshold' => $settings->notificationThreshold,
		]);
		
		$asset = Craft::$app->assets->getAssetById($this->assetId);
		if (!$asset || $asset->kind !== 'image') {
			$this->debugLog($settings, 'Skipping asset because it was not found or is not an image', [
				'assetId' => $this->assetId,
			]);
			Craft::info("ImageQualityChecker/AnalyzeImageJob: Asset not found or not an image.", __METHOD__);
			$this->updateProgress($queue, 1, 'Skipped: asset not found');
			return;
		}
		$this->debugLog($settings, 'Loaded asset', [
			'assetId' => $asset->id,
			'filename' => $asset->filename,
			'kind' => $asset->kind,
			'mimeType' => $asset->mimeType,
		]);
				
		$volume = $asset->getVolume();
		$volumeHandle = $volume->handle ?? null;		
		$allowedHandles = $settings->allowedAssetFieldHandles;
		
		if (empty($allowedHandles)) {
			$this->debugLog($settings, 'Skipping asset because no volumes are selected', [
				'volumeHandle' => $volumeHandle,
			]);
			Craft::info("ImageQualityChecker/AnalyzeImageJob: No asset fields selected in settings — skipping.", __METHOD__);
			$this->updateProgress($queue, 1, 'Skipped: no volumes selected');
			return;
		} 
		
		if (!in_array($volumeHandle, $allowedHandles, true)) {
			$this->debugLog($settings, 'Skipping asset because volume is not selected', [
				'volumeHandle' => $volumeHandle,
				'allowedHandles' => $allowedHandles,
			]);
			Craft::info("ImageQualityChecker/AnalyzeImageJob: Asset uploaded via non-selected volume '{$volumeHandle}' — skipping.", __METHOD__);
			$this->updateProgress($queue, 1, 'Skipped: volume not selected');
			return;
		} 
		
		$localPath = $this->getFullAssetPathById($asset->id);
		
		if (!$localPath || !file_exists($localPath)) {
			$this->debugLog($settings, 'Skipping asset because local path was not found', [
				'assetId' => $asset->id,
				'localPath' => $localPath,
			]);
			Craft::warning("ImageQualityChecker/AnalyzeImageJob: File not found for asset ID {$asset->id}", __METHOD__);
			$this->updateProgress($queue, 1, 'Skipped: file not found');
			return;
		}
		$this->debugLog($settings, 'Resolved local asset file', [
			'localPath' => $localPath,
			'fileSize' => filesize($localPath),
			'ownership' => $this->getFileOwnershipContext($localPath),
		]);
		
		$imageBase64 = base64_encode(file_get_contents($localPath));
		
		$apiKey = $settings->chatGptApiKey;

		if (!$apiKey) {
			$this->debugLog($settings, 'Skipping analysis because OpenAI API key is missing');
			Craft::warning("AnalyzeImageJob: API key missing in settings.", __METHOD__);
			$this->updateProgress($queue, 1, 'Skipped: missing OpenAI API key');
			return;
		}

		$client = Craft::createGuzzleClient();
		$imageUrl = $asset->getUrl();
		$relatedEntry = $this->getParentEntryForAsset($asset->id);
		$entryTitle = $relatedEntry?->title ?? null;
		$entryLink = $relatedEntry?->getCpEditUrl() ?? null;
		$author = $relatedEntry?->getAuthor()?->username
		?? ($asset->uploaderId ? Craft::$app->users->getUserById($asset->uploaderId)?->username : 'Onbekend');

		if (
			$settings->imageEnhancementMode !== Settings::ENHANCEMENT_DISABLED &&
			$settings->imageEnhancementTrigger === Settings::ENHANCEMENT_TRIGGER_ALWAYS
		) {
			$this->updateProgress($queue, 0.15, 'Skipping quality check');
			$this->debugLog($settings, 'Always-enhance trigger enabled; skipping quality analysis');
			$this->updateProgress($queue, 0.45, 'Starting enhancement');
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
			$this->updateProgress($queue, 0.80, 'Replacement complete');
			$this->debugLog($settings, 'Enhancement result', $data['enhancement']);
			$this->updateProgress($queue, 0.90, 'Sending notifications');
			$this->sendSlackNotification($data);
			$this->sendEmailNotification($data);
			$this->updateProgress($queue, 1, 'Done');
			return;
		}

		$model = $this->resolveChatGptModel($client, $settings->chatGptModel, $apiKey);
		$mime = $asset->mimeType;
		$this->updateProgress($queue, 0.15, 'Sending quality check');
		$this->debugLog($settings, 'Sending image to OpenAI for analysis', [
			'configuredModel' => $settings->chatGptModel,
			'resolvedModel' => $model,
			'mimeType' => $mime,
		]);
		$requestJson = [
			'model' => $model,
			'messages' => [[
				'role' => 'user',
				'content' => [
					['type' => 'text', 'text' => $settings->chatGptPrompt . '. Return a JSON object without any other data, markup or styling. Example: {"score": X, "reason": "..."}. Translate the value of reason to ' . $settings->chatGptResultLanguage . '.'],
					['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $imageBase64]],
				]
			]],
			'max_completion_tokens' => 500,
		];
		try {
			$response = $client->post('https://api.openai.com/v1/chat/completions', [
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type'  => 'application/json',
				],
				'json' => $requestJson,
			]);
		} catch (\Throwable $e) {
			$this->debugLog($settings, 'OpenAI analysis request failed', [
				'error' => $e->getMessage(),
				'resolvedModel' => $model,
			]);
			Craft::error('ImageQualityChecker: OpenAI analysis request failed: ' . $e->getMessage(), __METHOD__);
			$this->updateProgress($queue, 1, 'Failed: quality check request failed');
			return;
		}

		$json = json_decode((string) $response->getBody(), true);
		$content = $json['choices'][0]['message']['content'] ?? null;

		if (!$content) {
			$this->debugLog($settings, 'OpenAI analysis returned no message content', [
				'responseKeys' => is_array($json) ? array_keys($json) : [],
			]);
			Craft::error("AnalyzeImageJob: No response from ChatGPT.", __METHOD__);
			$this->updateProgress($queue, 1, 'Failed: no quality check response');
			return;
		}

		$matches = [];
		preg_match('/\\{.*\\}/s', $content, $matches);
		$data = isset($matches[0]) ? json_decode($matches[0], true) : null;

		$score = $data['score'] ?? 'Onbekend';
		$reason = $data['reason'] ?? $content;
		$this->debugLog($settings, 'Parsed OpenAI analysis result', [
			'score' => $score,
			'scoreNum' => (int) $score,
			'threshold' => $settings->notificationThreshold,
			'willNotify' => (int) $score > 0 && (int) $score <= $settings->notificationThreshold,
			'reasonPreview' => substr((string) $reason, 0, 160),
		]);
		$this->updateProgress($queue, 0.35, 'Quality check complete');

		$scoreEmoji = '❓';
		$scoreLabel = 'Onbekend';
		$scoreNum = (int) $score;
		
		if ($scoreNum <= 39) {
			$scoreEmoji = '🔴';
			$scoreLabel = 'Slecht';
		} elseif ($scoreNum <= 59) {
			$scoreEmoji = '🟠';
			$scoreLabel = 'Matig';
		} elseif ($scoreNum <= 79) {
			$scoreEmoji = '🟡';
			$scoreLabel = 'Goed';
		} elseif ($scoreNum <= 100) {
			$scoreEmoji = '🟢';
			$scoreLabel = 'Uitstekend';
		}
		
		$data = [
			'scoreNum' => $score,
			'scoreEmoji' => $scoreEmoji,
			'scoreLabel' => $scoreLabel,
			'author' => $author,
			'imageUrl' => $imageUrl,
			'entryLink' => $entryLink,
			'entryTitle' => $entryTitle,
			'reason' => $reason,
		];
		
		// Send notification if score is below threshold
		if($scoreNum > 0 && $scoreNum <= $settings->notificationThreshold) {
			$this->debugLog($settings, 'Score reached threshold; running enhancement and notifications', [
				'scoreNum' => $scoreNum,
				'threshold' => $settings->notificationThreshold,
			]);
			$this->updateProgress($queue, 0.45, 'Starting enhancement');
			$data['enhancement'] = $this->enhanceImageIfEnabled($client, $settings, $asset, $localPath, $apiKey);
			$this->updateProgress($queue, 0.80, 'Replacement complete');
			$this->debugLog($settings, 'Enhancement result', $data['enhancement']);
			$this->updateProgress($queue, 0.90, 'Sending notifications');
			$this->sendSlackNotification($data);
			$this->sendEmailNotification($data);
			$this->updateProgress($queue, 1, 'Done');
		} else {
			$this->debugLog($settings, 'Score did not reach threshold; no enhancement or notification sent', [
				'scoreNum' => $scoreNum,
				'threshold' => $settings->notificationThreshold,
			]);
			$this->updateProgress($queue, 1, 'Done: score above threshold');
		} 
	}

	private function enhanceImageIfEnabled(ClientInterface $client, Settings $settings, Asset $asset, string $localPath, string $apiKey): array
	{
		if ($settings->imageEnhancementMode === Settings::ENHANCEMENT_DISABLED) {
			$this->debugLog($settings, 'Enhancement disabled');
			return [
				'label' => 'Niet vervangen',
				'status' => 'Enhancement staat uit.',
			];
		}

		if ($settings->imageEnhancementMode === Settings::ENHANCEMENT_SAFE) {
			return $this->safeEnhanceImage($settings, $asset, $localPath);
		}

		if ($settings->imageEnhancementMode === Settings::ENHANCEMENT_CREATIVE) {
			return $this->creativeEnhanceImage($client, $settings, $asset, $localPath, $apiKey);
		}

		return [
			'label' => 'Niet vervangen',
			'status' => 'Onbekende enhancement mode.',
		];
	}

	private function safeEnhanceImage(Settings $settings, Asset $asset, string $localPath): array
	{
		if (!class_exists(Imagick::class)) {
			$this->debugLog($settings, 'Imagick enhancement skipped because Imagick is not available');
			Craft::warning('ImageQualityChecker: Imagick is required for safe image enhancement.', __METHOD__);
			return [
				'label' => 'Niet vervangen',
				'status' => 'Safe optimization mislukt: Imagick ontbreekt.',
			];
		}

		try {
			$this->debugLog($settings, 'Starting Imagick enhancement', [
				'assetId' => $asset->id,
				'localPath' => $localPath,
				'originalFileSize' => file_exists($localPath) ? filesize($localPath) : null,
				'originalOwnership' => $this->getFileOwnershipContext($localPath),
			]);
			$image = new Imagick($localPath);
			$originalWidth = $image->getImageWidth();
			$originalHeight = $image->getImageHeight();
			$image->autoOrient();
			$image->enhanceImage();
			$image->unsharpMaskImage(0.7, 0.6, 1.0, 0.05);

			$maxWidth = max(1, $settings->safeEnhancementMaxWidth);
			if ($image->getImageWidth() < $maxWidth) {
				$image->resizeImage($maxWidth, 0, Imagick::FILTER_LANCZOS, 1);
			}

			if (in_array($asset->mimeType, ['image/jpeg', 'image/jpg'], true)) {
				$image->setImageCompression(Imagick::COMPRESSION_JPEG);
				$image->setImageCompressionQuality(min(100, max(1, $settings->safeEnhancementJpegQuality)));
				$image->setImageFormat('jpeg');
			} elseif ($asset->mimeType === 'image/png') {
				$image->setImageFormat('png');
			}

			$image->stripImage();
			$tempPath = $this->getTempReplacementPath($asset);
			$image->writeImage($tempPath);
			$this->debugLog($settings, 'Imagick wrote replacement temp file', [
				'tempPath' => $tempPath,
				'tempFileExists' => file_exists($tempPath),
				'tempFileSize' => file_exists($tempPath) ? filesize($tempPath) : null,
				'originalWidth' => $originalWidth,
				'originalHeight' => $originalHeight,
				'newWidth' => $image->getImageWidth(),
				'newHeight' => $image->getImageHeight(),
				'tempOwnership' => $this->getFileOwnershipContext($tempPath),
			]);
			$image->clear();
			$image->destroy();

			Craft::$app->assets->replaceAssetFile($asset, $tempPath, $asset->filename);
			@unlink($tempPath);
			$this->debugLog($settings, 'Imagick replacement completed', [
				'assetId' => $asset->id,
				'filename' => $asset->filename,
				'replacedFileExists' => file_exists($localPath),
				'replacedOwnership' => $this->getFileOwnershipContext($localPath),
			]);

			return [
				'label' => 'Vervangen met safe optimization',
				'status' => 'Origineel bestand is vervangen door een lokaal geoptimaliseerde versie.',
			];
		} catch (\Throwable $e) {
			$this->debugLog($settings, 'Imagick enhancement failed', [
				'error' => $e->getMessage(),
			]);
			Craft::error('ImageQualityChecker: Safe image enhancement failed: ' . $e->getMessage(), __METHOD__);

			return [
				'label' => 'Niet vervangen',
				'status' => 'Safe optimization mislukt.',
			];
		}
	}

	private function creativeEnhanceImage(ClientInterface $client, Settings $settings, Asset $asset, string $localPath, string $apiKey): array
	{
		$handle = fopen($localPath, 'rb');

		if ($handle === false) {
			Craft::warning('ImageQualityChecker: Could not open image for AI enhancement.', __METHOD__);
			return [
				'label' => 'Niet vervangen',
				'status' => 'AI enhancement mislukt: bestand kon niet worden geopend.',
			];
		}

		try {
			[$originalWidth, $originalHeight] = getimagesize($localPath) ?: [null, null];
			$this->debugLog($settings, 'Starting OpenAI image enhancement', [
				'assetId' => $asset->id,
				'filename' => $asset->filename,
				'localPath' => $localPath,
				'fileSize' => file_exists($localPath) ? filesize($localPath) : null,
				'originalWidth' => $originalWidth,
				'originalHeight' => $originalHeight,
				'originalOwnership' => $this->getFileOwnershipContext($localPath),
			]);
			$response = $client->post('https://api.openai.com/v1/images/edits', [
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
				],
				'multipart' => [
					[
						'name' => 'model',
						'contents' => 'gpt-image-1',
					],
					[
						'name' => 'image',
						'contents' => $handle,
						'filename' => $asset->filename,
					],
					[
						'name' => 'prompt',
						'contents' => $settings->creativeEnhancementPrompt,
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
				$this->debugLog($settings, 'OpenAI image enhancement returned no image data', [
					'responseKeys' => is_array($data) ? array_keys($data) : [],
				]);
				Craft::warning('ImageQualityChecker: AI image enhancement returned no image data.', __METHOD__);
				return [
					'label' => 'Niet vervangen',
					'status' => 'AI enhancement gaf geen vervangende afbeelding terug.',
				];
			}

			$tempPath = $this->getTempReplacementPath($asset);
			file_put_contents($tempPath, base64_decode($imageData));
			if ($originalWidth && $originalHeight) {
				$this->normalizeReplacementImageDimensions($settings, $asset, $tempPath, $originalWidth, $originalHeight);
			}
			$normalizedSize = file_exists($tempPath) ? @getimagesize($tempPath) : null;
			$this->debugLog($settings, 'OpenAI image enhancement wrote replacement temp file', [
				'tempPath' => $tempPath,
				'tempFileExists' => file_exists($tempPath),
				'tempFileSize' => file_exists($tempPath) ? filesize($tempPath) : null,
				'normalizedWidth' => $normalizedSize[0] ?? null,
				'normalizedHeight' => $normalizedSize[1] ?? null,
				'tempOwnership' => $this->getFileOwnershipContext($tempPath),
			]);
			Craft::$app->assets->replaceAssetFile($asset, $tempPath, $asset->filename);
			@unlink($tempPath);
			$this->debugLog($settings, 'OpenAI image replacement completed', [
				'assetId' => $asset->id,
				'filename' => $asset->filename,
				'replacedFileExists' => file_exists($localPath),
				'replacedOwnership' => $this->getFileOwnershipContext($localPath),
			]);

			return [
				'label' => 'Vervangen met AI enhancement',
				'status' => 'Origineel bestand is vervangen door een OpenAI-geoptimaliseerde versie.',
			];
		} catch (\Throwable $e) {
			$this->debugLog($settings, 'OpenAI image enhancement failed', [
				'error' => $e->getMessage(),
			]);
			Craft::error('ImageQualityChecker: AI image enhancement failed: ' . $e->getMessage(), __METHOD__);

			return [
				'label' => 'Niet vervangen',
				'status' => 'AI enhancement mislukt.',
			];
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
	}

	private function normalizeReplacementImageDimensions(Settings $settings, Asset $asset, string $path, int $targetWidth, int $targetHeight): void
	{
		if (!class_exists(Imagick::class)) {
			$this->debugLog($settings, 'Skipping AI replacement dimension normalization because Imagick is not available', [
				'targetWidth' => $targetWidth,
				'targetHeight' => $targetHeight,
			]);
			return;
		}

		try {
			$image = new Imagick($path);
			$sourceWidth = $image->getImageWidth();
			$sourceHeight = $image->getImageHeight();

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

			$this->debugLog($settings, 'Cropped AI replacement to original asset dimensions', [
				'sourceWidth' => $sourceWidth,
				'sourceHeight' => $sourceHeight,
				'targetWidth' => $targetWidth,
				'targetHeight' => $targetHeight,
			]);
		} catch (\Throwable $e) {
			$this->debugLog($settings, 'AI replacement dimension normalization failed', [
				'error' => $e->getMessage(),
				'targetWidth' => $targetWidth,
				'targetHeight' => $targetHeight,
			]);
			Craft::warning('ImageQualityChecker: AI replacement dimension normalization failed: ' . $e->getMessage(), __METHOD__);
		}
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

	private function getProcessOwnershipContext(): array
	{
		$uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
		$gid = function_exists('posix_getegid') ? posix_getegid() : null;

		return [
			'currentUser' => get_current_user(),
			'uid' => $uid,
			'user' => $this->getUserName($uid),
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

		$line = 'ImageQualityChecker DEBUG: ' . $message;

		if (!empty($context)) {
			$line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		Craft::info($line, __METHOD__);
	}

	private function updateProgress($queue, float $progress, string $label): void
	{
		$this->setProgress($queue, $progress, $label);
	}

	/**
	 * Sends a Slack message with the image quality analysis results.
	 *
	 * @param array $data The formatted result data from the ChatGPT analysis.
	 */
	private function sendSlackNotification(array $data): void
	{
		$settings = ImageQualityChecker::getInstance()->getSettings();
	
		if (!$settings->slackNotification) {
			$this->debugLog($settings, 'Slack notification skipped because Slack notifications are disabled');
			return;
		}
	
		$blocks = [
			[
				'type' => 'section',
				'text' => [
					'type' => 'mrkdwn',
					'text' => $this->getSlackSummaryText($data),
				],
			],
			isset($data['enhancement']) ? [
				'type' => 'context',
				'elements' => [[
					'type' => 'mrkdwn',
					'text' => "*Enhancement:* {$data['enhancement']['status']}"
				]]
			] : null,
			/*[
				'type' => 'context',
				'elements' => [[
					'type' => 'mrkdwn',
					'text' => $data['reason']
				]]
			]*/
		];
		$blocks = array_values(array_filter($blocks));

		try {
			$client = Craft::createGuzzleClient();

			if ($settings->slackWebhookUrl) {
				$this->debugLog($settings, 'Sending Slack notification via webhook');
				$client->post($settings->slackWebhookUrl, [
					'json' => [
						'text' => 'Beeldkwaliteit analyse',
						'blocks' => $blocks,
						'unfurl_links' => false,
						'unfurl_media' => true,
					],
				]);
				$this->debugLog($settings, 'Slack webhook notification sent');
				return;
			}

			if (!$settings->slackBotToken || !$settings->slackChannel) {
				$this->debugLog($settings, 'Slack notification skipped because bot token or channel is missing', [
					'hasSlackBotToken' => (bool) $settings->slackBotToken,
					'slackChannel' => $settings->slackChannel,
				]);
				return;
			}

			$this->debugLog($settings, 'Sending Slack notification via bot token', [
				'slackChannel' => $settings->slackChannel,
			]);
			$response = $client->post('https://slack.com/api/chat.postMessage', [
				'headers' => [
					'Authorization' => 'Bearer ' . $settings->slackBotToken,
					'Content-Type' => 'application/json',
				],
				'json' => [
					'channel' => $settings->slackChannel,
					'text' => 'Beeldkwaliteit analyse',
					'blocks' => $blocks,
					'unfurl_links' => false,
					'unfurl_media' => true,
				],
			]);
			$responseData = json_decode((string) $response->getBody(), true);
			if (($responseData['ok'] ?? true) === false) {
				$this->debugLog($settings, 'Slack bot API returned an error', [
					'error' => $responseData['error'] ?? null,
				]);
				Craft::warning('ImageQualityChecker: Slack API error: ' . ($responseData['error'] ?? 'unknown'), __METHOD__);
				return;
			}
			$this->debugLog($settings, 'Slack bot notification sent');
		} catch (\Throwable $e) {
			$this->debugLog($settings, 'Slack notification failed', [
				'error' => $e->getMessage(),
			]);
			Craft::error('ImageQualityChecker: Slack notification failed: ' . $e->getMessage(), __METHOD__);
		}
	}

	/**
	 * Sends an HTML email with image quality analysis results to the author.
	 * CCs the configured recipient if set in plugin settings.
	 *
	 * @param array $data The formatted result data from the ChatGPT analysis.
	 */
	private function sendEmailNotification(array $data): void
	{
		$settings = ImageQualityChecker::getInstance()->getSettings();
	
		if (!$settings->emailNotification) {
			$this->debugLog($settings, 'Email notification skipped because email notifications are disabled');
			return;
		}
	
		$author = $data['author'] ?? null;
		$authorUser = $author ? Craft::$app->users->getUserByUsernameOrEmail($author) : null;
		$authorEmail = $authorUser?->email ?? null;
	
		if (!$authorEmail) {
			$this->debugLog($settings, 'Email notification skipped because no author email could be resolved', [
				'author' => $author,
			]);
			Craft::warning("ImageQualityChecker: Auteur heeft geen geldig e-mailadres, e-mail wordt niet verzonden.", __METHOD__);
			return;
		}
	
		$htmlBody = "<h2>📸 Beeldkwaliteit analyse</h2>
			<p><strong>Score:</strong> {$data['scoreEmoji']} {$data['scoreNum']}/100 ({$data['scoreLabel']})<br>
			<strong>Auteur:</strong> {$data['author']}<br>" .
			(isset($data['enhancement']) ? "<strong>Vervanging:</strong> {$data['enhancement']['label']}<br>" : '') .
			($data['entryLink'] ? "<strong>Artikel:</strong> <a href=\"{$data['entryLink']}\">{$data['entryTitle']}</a><br>" : '') .
			"</p>" .
			"<p><strong>Afbeelding:</strong><br>
				<a href=\"{$data['imageUrl']}\" target=\"_blank\">
					<img src=\"{$data['imageUrl']}\" alt=\"Geanalyseerde afbeelding\" style=\"max-width:400px; height:auto; border:1px solid #ddd;\">
				</a>
			</p>
			<p><strong>Toelichting:</strong><br>{$data['reason']}</p>";
	
		$mail = Craft::$app->getMailer()->compose()
			->setTo($authorEmail)
			->setSubject('Beeldkwaliteit analyse')
			->setHtmlBody($htmlBody);
	
		if (!empty($settings->emailNotificationRecipient)) {
			$mail->setCc($settings->emailNotificationRecipient);
		}
	
		$sent = $mail->send();
		$this->debugLog($settings, 'Email notification send attempted', [
			'authorEmail' => $authorEmail,
			'cc' => $settings->emailNotificationRecipient,
			'sent' => $sent,
		]);
	}

	private function getSlackSummaryText(array $data): string
	{
		$parts = [
			"*Beeldkwaliteit:* {$data['scoreEmoji']} {$data['scoreNum']}/100 ({$data['scoreLabel']})",
			"*Auteur:* {$data['author']}",
		];

		if (isset($data['enhancement'])) {
			$parts[] = "*Vervanging:* {$data['enhancement']['label']}";
		}

		if ($data['entryLink']) {
			$parts[] = "*Artikel:* <{$data['entryLink']}|{$data['entryTitle']}>";
		}

		$parts[] = "*Afbeelding:* <{$data['imageUrl']}|bekijken>";

		return implode("\n", $parts);
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
			Craft::warning('ImageQualityChecker: Could not resolve latest OpenAI model: ' . $e->getMessage(), __METHOD__);
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

	/**
	 * Attempts to find the parent entry related to the given asset ID.
	 *
	 * @param int $assetId The asset ID to search a parent entry for.
	 * @return Entry|null The related entry if found.
	 */
	private function getParentEntryForAsset(int $assetId): ?Entry
	{
		$sourceId = (new Query())
			->select(['sourceId'])
			->from(Table::RELATIONS)
			->where(['targetId' => $assetId])
			->scalar();
	
		if (!$sourceId) {
			return null;
		}
	
		$element = Craft::$app->elements->getElementById($sourceId, null, '*');
	
		if (!$element) {
			return null;
		}
	
		if ($element instanceof Entry) {
			return $element;
		}
	
		if (property_exists($element, 'ownerId') && $element->ownerId) {
			return Entry::find()
				->id($element->ownerId)
				->status(null)
				->one();
		}
	
		return null;
	}

	/**
	 * Returns the full file system path of an asset by ID.
	 *
	 * @param int $id The asset ID.
	 * @return string|null The full path or null if invalid.
	 */
	public function getFullAssetPathById(int $id): ?string
	{
		$asset = Asset::find()->id($id)->one();
	
		if (!$asset || $asset->kind !== 'image' || !in_array($asset->mimeType, ['image/jpeg', 'image/png', 'image/jpg'])) {
			return null;
		}
	
		$fsPath = Craft::getAlias($asset->getFs()->path);
		return $fsPath . DIRECTORY_SEPARATOR . $asset->folderPath . $asset->filename;
	}

	/**
	 * Returns the default description for this job.
	 *
	 * @return string
	 */
	protected function defaultDescription(): string
	{
		return 'Analyse image quality with ChatGPT';
	}
}
