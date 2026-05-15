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
		
		$asset = Craft::$app->assets->getAssetById($this->assetId);
		if (!$asset || $asset->kind !== 'image') {
			Craft::info("ImageQualityChecker/AnalyzeImageJob: Asset not found or not an image.", __METHOD__);
			return;
		}
				
		$volume = $asset->getVolume();
		$volumeHandle = $volume->handle ?? null;		
		$allowedHandles = $settings->allowedAssetFieldHandles;
		
		if (empty($allowedHandles)) {
			Craft::info("ImageQualityChecker/AnalyzeImageJob: No asset fields selected in settings — skipping.", __METHOD__);
			return;
		} 
		
		if (!in_array($volumeHandle, $allowedHandles, true)) {
			Craft::info("ImageQualityChecker/AnalyzeImageJob: Asset uploaded via non-selected volume '{$volumeHandle}' — skipping.", __METHOD__);
			return;
		} 
		
		$localPath = $this->getFullAssetPathById($asset->id);
		
		if (!$localPath || !file_exists($localPath)) {
			Craft::warning("ImageQualityChecker/AnalyzeImageJob: File not found for asset ID {$asset->id}", __METHOD__);
			return;
		}
		
		$imageBase64 = base64_encode(file_get_contents($localPath));
		
		$apiKey = $settings->chatGptApiKey;

		if (!$apiKey) {
			Craft::warning("AnalyzeImageJob: API key missing in settings.", __METHOD__);
			return;
		}

		$client = Craft::createGuzzleClient();
		$model = $this->resolveChatGptModel($client, $settings->chatGptModel, $apiKey);
		$mime = $asset->mimeType;
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
		$response = $client->post('https://api.openai.com/v1/chat/completions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
				'Content-Type'  => 'application/json',
			],
			'json' => $requestJson,
		]);

		$json = json_decode((string) $response->getBody(), true);
		$content = $json['choices'][0]['message']['content'] ?? null;

		if (!$content) {
			Craft::error("AnalyzeImageJob: No response from ChatGPT.", __METHOD__);
			return;
		}

		$matches = [];
		preg_match('/\\{.*\\}/s', $content, $matches);
		$data = isset($matches[0]) ? json_decode($matches[0], true) : null;

		$score = $data['score'] ?? 'Onbekend';
		$reason = $data['reason'] ?? $content;

		$imageUrl = $asset->getUrl();
		$relatedEntry = $this->getParentEntryForAsset($asset->id);

		$entryTitle = $relatedEntry?->title ?? null;
		$entryLink = $relatedEntry?->getCpEditUrl() ?? null;
		
		$author = $relatedEntry?->getAuthor()?->username
		?? ($asset->uploaderId ? Craft::$app->users->getUserById($asset->uploaderId)?->username : 'Onbekend');
			   
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
			$data['enhancement'] = $this->enhanceImageIfEnabled($client, $settings, $asset, $localPath, $apiKey);
			$this->sendSlackNotification($data);
			$this->sendEmailNotification($data);
		} 
	}

	private function enhanceImageIfEnabled(ClientInterface $client, Settings $settings, Asset $asset, string $localPath, string $apiKey): array
	{
		if ($settings->imageEnhancementMode === Settings::ENHANCEMENT_DISABLED) {
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
			Craft::warning('ImageQualityChecker: Imagick is required for safe image enhancement.', __METHOD__);
			return [
				'label' => 'Niet vervangen',
				'status' => 'Safe optimization mislukt: Imagick ontbreekt.',
			];
		}

		try {
			$image = new Imagick($localPath);
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
			$image->clear();
			$image->destroy();

			Craft::$app->assets->replaceAssetFile($asset, $tempPath, $asset->filename);
			@unlink($tempPath);

			return [
				'label' => 'Vervangen met safe optimization',
				'status' => 'Origineel bestand is vervangen door een lokaal geoptimaliseerde versie.',
			];
		} catch (\Throwable $e) {
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
				Craft::warning('ImageQualityChecker: AI image enhancement returned no image data.', __METHOD__);
				return [
					'label' => 'Niet vervangen',
					'status' => 'AI enhancement gaf geen vervangende afbeelding terug.',
				];
			}

			$tempPath = $this->getTempReplacementPath($asset);
			file_put_contents($tempPath, base64_decode($imageData));
			Craft::$app->assets->replaceAssetFile($asset, $tempPath, $asset->filename);
			@unlink($tempPath);

			return [
				'label' => 'Vervangen met AI enhancement',
				'status' => 'Origineel bestand is vervangen door een OpenAI-geoptimaliseerde versie.',
			];
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: AI image enhancement failed: ' . $e->getMessage(), __METHOD__);

			return [
				'label' => 'Niet vervangen',
				'status' => 'AI enhancement mislukt.',
			];
		} finally {
			fclose($handle);
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

	/**
	 * Sends a Slack message with the image quality analysis results.
	 *
	 * @param array $data The formatted result data from the ChatGPT analysis.
	 */
	private function sendSlackNotification(array $data): void
	{
		$settings = ImageQualityChecker::getInstance()->getSettings();
	
		if (!$settings->slackNotification || !$settings->slackBotToken || !$settings->slackChannel) {
			return;
		}
	
		$blocks = [
			[
				'type' => 'header',
				'text' => [
					'type' => 'plain_text',
					'text' => '📸 Beeldkwaliteit geanalyseerd',
					'emoji' => false
				]
			],
			[
				'type' => 'section',
				'fields' => array_filter([
					[
						'type' => 'mrkdwn',
						'text' => "*Score:*\n{$data['scoreEmoji']} *{$data['scoreNum']}/100* ({$data['scoreLabel']})"
					],
					[
						'type' => 'mrkdwn',
						'text' => "*Auteur:*\n{$data['author']}"
					],
					[
						'type' => 'mrkdwn',
						'text' => "*Afbeelding:*\n<{$data['imageUrl']}|Bekijken>"
					],
					isset($data['enhancement']) ? [
						'type' => 'mrkdwn',
						'text' => "*Vervanging:*\n{$data['enhancement']['label']}"
					] : null,
					$data['entryLink'] ? [
						'type' => 'mrkdwn',
						'text' => "*Artikel:*\n<{$data['entryLink']}|{$data['entryTitle']}>"
					] : null,
				])
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
	
		Craft::createGuzzleClient()->post('https://slack.com/api/chat.postMessage', [
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
			return;
		}
	
		$author = $data['author'] ?? null;
		$authorUser = $author ? Craft::$app->users->getUserByUsernameOrEmail($author) : null;
		$authorEmail = $authorUser?->email ?? null;
	
		if (!$authorEmail) {
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
	
		$mail->send();
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
