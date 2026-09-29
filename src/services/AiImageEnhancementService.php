<?php

namespace arjanbrinkman\craftimageenhancer\services;

use arjanbrinkman\craftimageenhancer\helpers\FileHelper;
use arjanbrinkman\craftimageenhancer\helpers\HttpHelper;
use arjanbrinkman\craftimageenhancer\helpers\ImageHelper;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\models\Settings;
use craft\base\Component;
use craft\elements\Asset;
use GuzzleHttp\ClientInterface;

class AiImageEnhancementService extends Component
{
	public function getProviderLabel(Settings $settings, array $providerOptions = []): string
	{
		return match ($this->resolveProvider($settings, $providerOptions)) {
			Settings::IMAGE_PROVIDER_XAI => 'Grok Imagine',
			Settings::IMAGE_PROVIDER_GOOGLE => 'Google Nano Banana',
			default => 'OpenAI',
		};
	}

	public function getProviderModel(Settings $settings, array $providerOptions = []): string
	{
		return match ($this->resolveProvider($settings, $providerOptions)) {
			Settings::IMAGE_PROVIDER_XAI => (string) ($providerOptions['model'] ?? $settings->xAiImageEnhancementModel),
			Settings::IMAGE_PROVIDER_GOOGLE => (string) ($providerOptions['model'] ?? $settings->googleImageEnhancementModel),
			default => (string) ($providerOptions['model'] ?? $settings->imageEnhancementModel),
		};
	}

	public function getConfiguredApiKey(Settings $settings, array $providerOptions = []): string
	{
		return match ($this->resolveProvider($settings, $providerOptions)) {
			Settings::IMAGE_PROVIDER_XAI => $settings->getResolvedXAiApiKey(),
			Settings::IMAGE_PROVIDER_GOOGLE => $settings->getResolvedGoogleAiApiKey(),
			default => $settings->getResolvedChatGptApiKey(),
		};
	}

	public function enhanceToTempFile(
		ClientInterface $client,
		Settings $settings,
		Asset $asset,
		string $localPath,
		array $providerOptions = [],
		?string $customPrompt = null,
	): string
	{
		$provider = $this->resolveProvider($settings, $providerOptions);
		$model = $this->getProviderModel($settings, $providerOptions);
		$apiKey = $this->getConfiguredApiKey($settings, $providerOptions);
		if ($apiKey === '') {
			throw new \RuntimeException($this->getProviderLabel($settings, $providerOptions) . ' API key is missing.');
		}

		$customPrompt = trim((string) $customPrompt);
		$prompt = $customPrompt !== ''
			? $this->getCustomEditPrompt($customPrompt)
			: ImageEnhancer::getInstance()->runtimeSettings->getCreativeEnhancementPromptForRequest($settings);

		return match ($provider) {
			Settings::IMAGE_PROVIDER_XAI => $this->enhanceWithXai($client, $asset, $localPath, $apiKey, $model, $prompt),
			Settings::IMAGE_PROVIDER_GOOGLE => $this->enhanceWithGoogle($client, $asset, $localPath, $apiKey, $model, $prompt),
			default => $this->enhanceWithOpenAi($client, $asset, $localPath, $apiKey, $model, $prompt),
		};
	}

	private function getCustomEditPrompt(string $customPrompt): string
	{
		return "Custom image editing request:\n" . trim($customPrompt) . "\n\n" .
			'Apply only the requested edit. Unless the request explicitly says otherwise, preserve the original dimensions, crop, aspect ratio, and all unrelated image content. Do not make unrelated creative changes.';
	}

	private function resolveProvider(Settings $settings, array $providerOptions): string
	{
		$provider = (string) ($providerOptions['provider'] ?? $settings->imageEnhancementProvider);

		if ($provider === Settings::IMAGE_PROVIDER_FRONTEND) {
			return Settings::IMAGE_PROVIDER_OPENAI;
		}

		return in_array($provider, [
			Settings::IMAGE_PROVIDER_OPENAI,
			Settings::IMAGE_PROVIDER_XAI,
			Settings::IMAGE_PROVIDER_GOOGLE,
		], true) ? $provider : Settings::IMAGE_PROVIDER_OPENAI;
	}

	private function enhanceWithOpenAi(ClientInterface $client, Asset $asset, string $localPath, string $apiKey, string $model, string $prompt): string
	{
		$handle = fopen($localPath, 'rb');
		if ($handle === false) {
			throw new \RuntimeException('Could not open the original asset file.');
		}

		try {
			$response = $client->request('POST', 'https://api.openai.com/v1/images/edits', HttpHelper::withTimeouts(HttpHelper::IMAGE_TIMEOUT, [
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
				],
				'multipart' => [
					[
						'name' => 'model',
						'contents' => $model,
					],
					[
						'name' => 'image',
						'contents' => $handle,
						'filename' => $asset->filename,
					],
					[
						'name' => 'prompt',
						'contents' => $prompt,
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
			]));
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}

		$data = json_decode((string) $response->getBody(), true);
		$imageData = $data['data'][0]['b64_json'] ?? null;

		if (!$imageData) {
			throw new \RuntimeException('OpenAI returned no enhanced image data.');
		}

		return $this->writeBase64ImageToTempFile($asset, $imageData);
	}

	private function enhanceWithXai(ClientInterface $client, Asset $asset, string $localPath, string $apiKey, string $model, string $prompt): string
	{
		$mimeType = $asset->mimeType ?: 'image/jpeg';
		$imageDataUri = 'data:' . $mimeType . ';base64,' . $this->getBase64LocalImage($localPath);

		$response = $client->request('POST', 'https://api.x.ai/v1/images/edits', HttpHelper::withTimeouts(HttpHelper::IMAGE_TIMEOUT, [
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
				'Content-Type' => 'application/json',
			],
			'json' => [
				'model' => $model,
				'prompt' => $prompt,
				'image' => [
					'type' => 'image_url',
					'url' => $imageDataUri,
				],
			],
		]));
		unset($imageDataUri);
		$data = json_decode((string) $response->getBody(), true);

		return $this->writeProviderImageResponseToTempFile($client, $asset, is_array($data) ? $data : null, 'xAI');
	}

	private function enhanceWithGoogle(ClientInterface $client, Asset $asset, string $localPath, string $apiKey, string $model, string $prompt): string
	{
		$mimeType = $asset->mimeType ?: 'image/jpeg';
		$encodedModel = rawurlencode($model);
		$response = $client->request('POST', "https://generativelanguage.googleapis.com/v1/models/{$encodedModel}:generateContent", HttpHelper::withTimeouts(HttpHelper::IMAGE_TIMEOUT, [
			'headers' => [
				'x-goog-api-key' => $apiKey,
				'Content-Type' => 'application/json',
			],
			'json' => [
				'contents' => [[
					'role' => 'user',
					'parts' => [
						['text' => $prompt],
						[
							'inline_data' => [
								'mime_type' => $mimeType,
								'data' => $this->getBase64LocalImage($localPath),
							],
						],
					],
				]],
			],
		]));
		$data = json_decode((string) $response->getBody(), true);
		$imageData = $this->extractGoogleImageData(is_array($data) ? $data : null);

		if (!$imageData) {
			throw new \RuntimeException('Google Nano Banana returned no enhanced image data.');
		}

		return $this->writeBase64ImageToTempFile($asset, $imageData);
	}

	private function writeProviderImageResponseToTempFile(ClientInterface $client, Asset $asset, ?array $data, string $providerLabel): string
	{
		$imageData = $data['data'][0]['b64_json'] ?? $data['b64_json'] ?? null;
		if (is_string($imageData) && $imageData !== '') {
			return $this->writeBase64ImageToTempFile($asset, $imageData);
		}

		$imageUrl = $data['data'][0]['url'] ?? $data['url'] ?? null;
		if (is_string($imageUrl) && $imageUrl !== '') {
			if (str_starts_with($imageUrl, 'data:')) {
				[, $base64] = explode(',', $imageUrl, 2) + [null, null];
				if ($base64) {
					return $this->writeBase64ImageToTempFile($asset, $base64);
				}
			}

			return $this->downloadProviderImage($client, $asset, $imageUrl, $providerLabel);
		}

		throw new \RuntimeException($providerLabel . ' returned no enhanced image data.');
	}

	private function extractGoogleImageData(?array $data): ?string
	{
		foreach ($data['candidates'] ?? [] as $candidate) {
			foreach ($candidate['content']['parts'] ?? [] as $part) {
				$inlineData = $part['inline_data'] ?? $part['inlineData'] ?? null;
				if (is_array($inlineData) && !empty($inlineData['data'])) {
					return $inlineData['data'];
				}
			}
		}

		return null;
	}

	private function writeBase64ImageToTempFile(Asset $asset, string $imageData): string
	{
		$decodedImage = base64_decode($imageData, true);
		if ($decodedImage === false) {
			throw new \RuntimeException('Provider returned invalid base64 image data.');
		}

		$tempPath = FileHelper::createTempPathForAsset($asset);
		if (file_put_contents($tempPath, $decodedImage) === false) {
			FileHelper::delete($tempPath);
			throw new \RuntimeException('Could not write enhanced image data to a temporary file.');
		}

		return $tempPath;
	}

	private function getBase64LocalImage(string $localPath): string
	{
		$imageData = file_get_contents($localPath);
		if ($imageData === false) {
			throw new \RuntimeException('Could not read the original asset file.');
		}

		return base64_encode($imageData);
	}

	/**
	 * Downloads a provider-hosted result image. Only https URLs on x.ai hosts are fetched
	 * (every redirect hop is re-checked), with timeouts and a size cap, and the result must
	 * be an image.
	 *
	 * @throws \RuntimeException
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	private function downloadProviderImage(ClientInterface $client, Asset $asset, string $imageUrl, string $providerLabel): string
	{
		if (!HttpHelper::isAllowedXaiUrl($imageUrl)) {
			throw new \RuntimeException($providerLabel . ' returned an invalid image URL.');
		}

		$tempPath = FileHelper::createTempPathForAsset($asset);
		HttpHelper::downloadToFile(
			$client,
			$imageUrl,
			$tempPath,
			HttpHelper::MAX_IMAGE_DOWNLOAD_BYTES,
			HttpHelper::withTimeouts(HttpHelper::DOWNLOAD_TIMEOUT),
			[HttpHelper::class, 'isAllowedXaiUrl'],
		);

		if (ImageHelper::validateImageInfo(ImageHelper::getImageInfo($tempPath)) !== null) {
			FileHelper::delete($tempPath);
			throw new \RuntimeException($providerLabel . ' returned a file that is not a supported image.');
		}

		return $tempPath;
	}
}
