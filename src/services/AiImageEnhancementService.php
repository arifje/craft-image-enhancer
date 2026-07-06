<?php

namespace arjanbrinkman\craftimageenhancer\services;

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
			Settings::IMAGE_PROVIDER_XAI => trim($settings->xAiApiKey),
			Settings::IMAGE_PROVIDER_GOOGLE => trim($settings->googleAiApiKey),
			default => trim($settings->chatGptApiKey),
		};
	}

	public function enhanceToTempFile(ClientInterface $client, Settings $settings, Asset $asset, string $localPath, array $providerOptions = []): string
	{
		$provider = $this->resolveProvider($settings, $providerOptions);
		$model = $this->getProviderModel($settings, $providerOptions);
		$apiKey = $this->getConfiguredApiKey($settings, $providerOptions);
		if ($apiKey === '') {
			throw new \RuntimeException($this->getProviderLabel($settings, $providerOptions) . ' API key is missing.');
		}

		$prompt = ImageEnhancer::getInstance()->runtimeSettings->getCreativeEnhancementPromptForRequest($settings);

		return match ($provider) {
			Settings::IMAGE_PROVIDER_XAI => $this->enhanceWithXai($client, $asset, $localPath, $apiKey, $model, $prompt),
			Settings::IMAGE_PROVIDER_GOOGLE => $this->enhanceWithGoogle($client, $asset, $localPath, $apiKey, $model, $prompt),
			default => $this->enhanceWithOpenAi($client, $asset, $localPath, $apiKey, $model, $prompt),
		};
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
			$response = $client->post('https://api.openai.com/v1/images/edits', [
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
			]);
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

		$response = $client->post('https://api.x.ai/v1/images/edits', [
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
		]);
		$data = json_decode((string) $response->getBody(), true);

		return $this->writeProviderImageResponseToTempFile($client, $asset, $data, 'xAI');
	}

	private function enhanceWithGoogle(ClientInterface $client, Asset $asset, string $localPath, string $apiKey, string $model, string $prompt): string
	{
		$mimeType = $asset->mimeType ?: 'image/jpeg';
		$encodedModel = rawurlencode($model);
		$response = $client->post("https://generativelanguage.googleapis.com/v1/models/{$encodedModel}:generateContent", [
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
		]);
		$data = json_decode((string) $response->getBody(), true);
		$imageData = $this->extractGoogleImageData($data);

		if (!$imageData) {
			throw new \RuntimeException('Google Nano Banana returned no enhanced image data.');
		}

		return $this->writeBase64ImageToTempFile($asset, $imageData);
	}

	private function writeProviderImageResponseToTempFile(ClientInterface $client, Asset $asset, ?array $data, string $providerLabel): string
	{
		$imageData = $data['data'][0]['b64_json'] ?? $data['b64_json'] ?? null;
		if ($imageData) {
			return $this->writeBase64ImageToTempFile($asset, $imageData);
		}

		$imageUrl = $data['data'][0]['url'] ?? $data['url'] ?? null;
		if ($imageUrl) {
			if (str_starts_with($imageUrl, 'data:')) {
				[, $base64] = explode(',', $imageUrl, 2) + [null, null];
				if ($base64) {
					return $this->writeBase64ImageToTempFile($asset, $base64);
				}
			}

			$response = $client->get($imageUrl);
			$tempPath = $this->getTempReplacementPath($asset);
			if (file_put_contents($tempPath, (string) $response->getBody()) === false) {
				throw new \RuntimeException('Could not write enhanced image URL response to a temporary file.');
			}

			return $tempPath;
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

		$tempPath = $this->getTempReplacementPath($asset);
		if (file_put_contents($tempPath, $decodedImage) === false) {
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

	private function getTempReplacementPath(Asset $asset): string
	{
		$extension = pathinfo($asset->filename, PATHINFO_EXTENSION);
		$tempPath = tempnam(sys_get_temp_dir(), 'image-enhancer-');

		if (!$extension) {
			return $tempPath;
		}

		@unlink($tempPath);

		return $tempPath . '.' . $extension;
	}
}
