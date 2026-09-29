<?php

namespace arjanbrinkman\craftimageenhancer\services;

use arjanbrinkman\craftimageenhancer\helpers\FileHelper as PluginFileHelper;
use arjanbrinkman\craftimageenhancer\helpers\HttpHelper;
use arjanbrinkman\craftimageenhancer\helpers\JobStatus;
use arjanbrinkman\craftimageenhancer\models\Settings;
use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use GuzzleHttp\ClientInterface;

class AiVideoGenerationService extends Component
{
	public const PROVIDER_GOOGLE = 'google';
	public const PROVIDER_XAI = 'xai';
	public const GOOGLE_MODEL_GEMINI_OMNI_FLASH = 'gemini-omni-1.1-flash';
	public const XAI_MODEL_GROK_IMAGINE_VIDEO_1_5 = 'grok-imagine-video-1.5';
	public const XAI_MODEL_GROK_IMAGINE_VIDEO = 'grok-imagine-video';
	public const MAX_PROMPT_LENGTH = 4000;

	private const GOOGLE_API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';
	private const XAI_API_BASE_URL = 'https://api.x.ai/v1';
	private const MAX_PROVIDER_POLLS = 120;
	/** Wall-clock cap on polling, independent of how long each status request takes. */
	private const MAX_PROVIDER_WAIT_SECONDS = 900;
	private const PROVIDER_POLL_INTERVAL_MICROSECONDS = 5000000;
	/** Videos are only reachable through the job status, so they live as long as it does (plus a margin). */
	private const VIDEO_RETENTION_SECONDS = JobStatus::TTL + 900;
	private const MAX_VIDEO_DOWNLOAD_BYTES = 314572800;

	public static function providerOptions(): array
	{
		return [
			['label' => 'Google', 'value' => self::PROVIDER_GOOGLE],
			['label' => 'Grok Imagine (xAI)', 'value' => self::PROVIDER_XAI],
		];
	}

	public static function modelOptions(): array
	{
		return [
			self::PROVIDER_GOOGLE => [
				['label' => 'Gemini Omni 1.1 Flash', 'value' => self::GOOGLE_MODEL_GEMINI_OMNI_FLASH],
			],
			self::PROVIDER_XAI => [
				['label' => 'Grok Imagine Video 1.5', 'value' => self::XAI_MODEL_GROK_IMAGINE_VIDEO_1_5],
				['label' => 'Grok Imagine Video', 'value' => self::XAI_MODEL_GROK_IMAGINE_VIDEO],
			],
		];
	}

	public function getAvailableProviderOptions(Settings $settings): array
	{
		$configured = array_values(array_filter(
			self::providerOptions(),
			fn(array $option): bool => $this->getConfiguredApiKey($settings, (string) $option['value']) !== '',
		));

		return $configured !== [] ? $configured : self::providerOptions();
	}

	public function resolveProviderOptions(?string $provider, ?string $model): array|false
	{
		$provider = strtolower(trim((string) $provider));
		$provider = $provider !== '' ? $provider : self::PROVIDER_GOOGLE;
		$modelOptions = self::modelOptions()[$provider] ?? [];
		if ($modelOptions === []) {
			return false;
		}

		$model = trim((string) $model);
		$model = $model !== '' ? $model : (string) ($modelOptions[0]['value'] ?? '');
		if (!in_array($model, array_column($modelOptions, 'value'), true)) {
			return false;
		}

		return [
			'provider' => $provider,
			'model' => $model,
		];
	}

	public function getConfiguredApiKey(Settings $settings, string $provider): string
	{
		return match ($provider) {
			self::PROVIDER_GOOGLE => $settings->getResolvedGoogleAiApiKey(),
			self::PROVIDER_XAI => $settings->getResolvedXAiApiKey(),
			default => '',
		};
	}

	public function getProviderLabel(string $provider): string
	{
		foreach (self::providerOptions() as $option) {
			if ($option['value'] === $provider) {
				return (string) $option['label'];
			}
		}

		return 'Video provider';
	}

	public function createVideoToTempFile(
		ClientInterface $client,
		Settings $settings,
		Asset $asset,
		string $localPath,
		string $instructions = '',
		string $provider = self::PROVIDER_GOOGLE,
		string $model = self::GOOGLE_MODEL_GEMINI_OMNI_FLASH,
		?callable $onProgress = null,
		?callable $isCanceled = null,
	): string {
		$options = $this->resolveProviderOptions($provider, $model);
		if ($options === false) {
			throw new \RuntimeException('Unsupported video provider or model.');
		}

		$provider = $options['provider'];
		$model = $options['model'];
		$apiKey = $this->getConfiguredApiKey($settings, $provider);
		if ($apiKey === '') {
			throw new \RuntimeException($this->getProviderLabel($provider) . ' API key is missing.');
		}

		$imageBytes = file_get_contents($localPath);
		if ($imageBytes === false) {
			throw new \RuntimeException('Could not read the source image.');
		}

		$this->cleanupExpiredVideos();
		$this->throwIfCanceled($isCanceled);

		return match ($provider) {
			self::PROVIDER_GOOGLE => $this->createGoogleVideo(
				$client,
				$apiKey,
				$asset,
				$localPath,
				$imageBytes,
				$instructions,
				$model,
				$onProgress,
				$isCanceled,
			),
			self::PROVIDER_XAI => $this->createXAiVideo(
				$client,
				$apiKey,
				$asset,
				$imageBytes,
				$instructions,
				$model,
				$onProgress,
				$isCanceled,
			),
			default => throw new \RuntimeException('Unsupported video provider or model.'),
		};
	}

	public function getDownloadFilename(Asset $asset): string
	{
		$baseName = pathinfo($asset->filename, PATHINFO_FILENAME);
		$baseName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseName) ?: 'image';
		$baseName = trim($baseName, '-.') ?: 'image';

		return $baseName . '-video.mp4';
	}

	public function isManagedVideoPath(?string $path): bool
	{
		if (!$path || !is_file($path)) {
			return false;
		}

		$directory = realpath($this->getVideoDirectory());
		$realPath = realpath($path);

		return $directory !== false &&
			$realPath !== false &&
			str_starts_with($realPath, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
	}

	public function deleteVideo(?string $path): void
	{
		if ($this->isManagedVideoPath($path)) {
			@unlink((string) $path);
		}
	}

	public function cleanupExpiredVideos(): void
	{
		$cutoff = time() - self::VIDEO_RETENTION_SECONDS;
		foreach (glob($this->getVideoDirectory() . DIRECTORY_SEPARATOR . '*.mp4') ?: [] as $path) {
			if (is_file($path) && (filemtime($path) ?: 0) < $cutoff) {
				@unlink($path);
			}
		}
	}

	private function createGoogleVideo(
		ClientInterface $client,
		string $apiKey,
		Asset $asset,
		string $localPath,
		string $imageBytes,
		string $instructions,
		string $model,
		?callable $onProgress,
		?callable $isCanceled,
	): string {
		$this->reportProgress($onProgress, 0.2, 'Generating video with Google');
		$response = $client->request('POST', self::GOOGLE_API_BASE_URL . '/interactions', [
			'headers' => [
				'x-goog-api-key' => $apiKey,
				'Content-Type' => 'application/json',
			],
			'json' => [
				'model' => $model,
				'input' => [
					[
						'type' => 'image',
						'data' => base64_encode($imageBytes),
						'mime_type' => $this->getImageMimeType($asset),
					],
					[
						'type' => 'text',
						'text' => $this->buildPrompt($instructions),
					],
				],
				'response_format' => [
					'type' => 'video',
					'aspect_ratio' => $this->resolveAspectRatio($asset, $localPath),
					'resolution' => '720p',
					'delivery' => 'uri',
				],
			],
			'connect_timeout' => 30,
			'timeout' => 900,
		]);

		$this->throwIfCanceled($isCanceled);
		$data = $this->decodeJsonResponse($response->getBody()->getContents(), 'Google returned an invalid video response.');
		$output = $this->extractGoogleVideoOutput($data);
		if (!empty($output['data'])) {
			$this->reportProgress($onProgress, 0.9, 'Saving generated video');

			return $this->writeBase64Video((string) $output['data']);
		}

		$uri = (string) ($output['uri'] ?? '');
		if ($uri === '') {
			throw new \RuntimeException($this->extractErrorMessage($data, 'Google returned no generated video.'));
		}

		$fileId = $this->extractGoogleFileId($uri);
		if ($fileId === null) {
			throw new \RuntimeException('Google returned an invalid video file reference.');
		}
		$this->waitUntilGoogleFileIsActive($client, $apiKey, $fileId, $onProgress, $isCanceled);

		$this->throwIfCanceled($isCanceled);
		$this->reportProgress($onProgress, 0.9, 'Downloading generated video');

		return $this->downloadGoogleVideo($client, $apiKey, $fileId);
	}

	private function createXAiVideo(
		ClientInterface $client,
		string $apiKey,
		Asset $asset,
		string $imageBytes,
		string $instructions,
		string $model,
		?callable $onProgress,
		?callable $isCanceled,
	): string {
		$this->reportProgress($onProgress, 0.2, 'Generating video with Grok Imagine');
		$response = $client->request('POST', self::XAI_API_BASE_URL . '/videos/generations', [
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
				'Content-Type' => 'application/json',
			],
			'json' => [
				'model' => $model,
				'prompt' => $this->buildPrompt($instructions, false),
				'image' => [
					'url' => 'data:' . $this->getImageMimeType($asset) . ';base64,' . base64_encode($imageBytes),
				],
				'duration' => 8,
				'resolution' => '720p',
			],
			'connect_timeout' => 30,
			'timeout' => 120,
		]);

		$data = $this->decodeJsonResponse($response->getBody()->getContents(), 'xAI returned an invalid video response.');
		$requestId = trim((string) ($data['request_id'] ?? ''));
		if ($requestId === '') {
			throw new \RuntimeException($this->extractErrorMessage($data, 'xAI returned no video request ID.'));
		}

		$videoUrl = $this->waitForXAiVideo($client, $apiKey, $requestId, $onProgress, $isCanceled);
		$this->throwIfCanceled($isCanceled);
		$this->reportProgress($onProgress, 0.9, 'Downloading generated video');

		return $this->downloadXAiVideo($client, $videoUrl);
	}

	private function buildPrompt(string $instructions, bool $includeFirstFrameToken = true): string
	{
		$instructions = trim($instructions);
		$customInstructions = $instructions !== ''
			? $instructions
			: 'Use subtle, natural subject and environmental motion with a gentle camera movement.';
		$firstFrameToken = $includeFirstFrameToken ? '<FIRST_FRAME> ' : '';

		return $firstFrameToken . 'Create a short, realistic video from this source image in one continuous shot. ' .
			'Unless the video instructions explicitly request a change, keep the people, identity, appearance, clothing, objects, setting, lighting, and visual style consistent with the image. ' .
			'Do not add captions, logos, watermarks, unrelated people, or unrelated objects. ' .
			'Video instructions: ' . $customInstructions;
	}

	private function resolveAspectRatio(Asset $asset, string $localPath): string
	{
		$width = (int) ($asset->width ?? 0);
		$height = (int) ($asset->height ?? 0);
		if (!$width || !$height) {
			[$width, $height] = getimagesize($localPath) ?: [16, 9];
		}

		return $height > $width ? '9:16' : '16:9';
	}

	private function getImageMimeType(Asset $asset): string
	{
		return $asset->mimeType === 'image/png' ? 'image/png' : 'image/jpeg';
	}

	private function extractGoogleVideoOutput(array $data): array
	{
		foreach (['output_video', 'outputVideo'] as $key) {
			if (isset($data[$key]) && is_array($data[$key])) {
				return $data[$key];
			}
		}

		foreach ($data['steps'] ?? [] as $step) {
			foreach ($step['content'] ?? [] as $content) {
				if (($content['type'] ?? null) === 'video') {
					return is_array($content) ? $content : [];
				}
			}
		}

		return [];
	}

	private function waitUntilGoogleFileIsActive(
		ClientInterface $client,
		string $apiKey,
		string $fileId,
		?callable $onProgress,
		?callable $isCanceled,
	): void {
		$deadline = time() + self::MAX_PROVIDER_WAIT_SECONDS;
		for ($attempt = 0; $attempt < self::MAX_PROVIDER_POLLS && time() < $deadline; $attempt++) {
			$this->throwIfCanceled($isCanceled);
			$response = $client->request('GET', self::GOOGLE_API_BASE_URL . '/files/' . rawurlencode($fileId), [
				'headers' => ['x-goog-api-key' => $apiKey],
				'connect_timeout' => 15,
				'timeout' => 60,
			]);
			$data = $this->decodeJsonResponse($response->getBody()->getContents(), 'Google returned an invalid video file status.');
			$state = strtoupper((string) ($data['state']['name'] ?? $data['state'] ?? ''));

			if ($state === 'ACTIVE') {
				return;
			}
			if ($state === 'FAILED') {
				throw new \RuntimeException($this->extractErrorMessage($data, 'Google video processing failed.'));
			}

			$this->reportProviderProgress($onProgress, $attempt, 'Preparing Google video');
			usleep(self::PROVIDER_POLL_INTERVAL_MICROSECONDS);
		}

		throw new \RuntimeException('Google video processing timed out.');
	}

	private function waitForXAiVideo(
		ClientInterface $client,
		string $apiKey,
		string $requestId,
		?callable $onProgress,
		?callable $isCanceled,
	): string {
		$deadline = time() + self::MAX_PROVIDER_WAIT_SECONDS;
		for ($attempt = 0; $attempt < self::MAX_PROVIDER_POLLS && time() < $deadline; $attempt++) {
			$this->throwIfCanceled($isCanceled);
			$response = $client->request('GET', self::XAI_API_BASE_URL . '/videos/' . rawurlencode($requestId), [
				'headers' => ['Authorization' => 'Bearer ' . $apiKey],
				'connect_timeout' => 15,
				'timeout' => 60,
			]);
			$data = $this->decodeJsonResponse($response->getBody()->getContents(), 'xAI returned an invalid video status.');
			$status = strtolower((string) ($data['status'] ?? ''));

			if ($status === 'done') {
				$video = is_array($data['video'] ?? null) ? $data['video'] : [];
				$videoUrl = trim((string) ($video['url'] ?? ''));
				if ($videoUrl === '') {
					throw new \RuntimeException('xAI completed the request without a video URL.');
				}
				if (array_key_exists('respect_moderation', $video) && !$video['respect_moderation']) {
					throw new \RuntimeException('xAI did not return the video because of content moderation.');
				}

				return $videoUrl;
			}
			if (in_array($status, ['failed', 'expired'], true)) {
				throw new \RuntimeException($this->extractErrorMessage($data, 'xAI video generation ' . $status . '.'));
			}

			$this->reportProviderProgress($onProgress, $attempt, 'Preparing Grok Imagine video');
			usleep(self::PROVIDER_POLL_INTERVAL_MICROSECONDS);
		}

		throw new \RuntimeException('xAI video generation timed out.');
	}

	private function downloadGoogleVideo(ClientInterface $client, string $apiKey, string $fileId): string
	{
		// The API key header is dropped if Google redirects to another host (e.g. storage).
		return $this->downloadRemoteVideo(
			$client,
			self::GOOGLE_API_BASE_URL . '/files/' . rawurlencode($fileId) . ':download',
			[
				'headers' => ['x-goog-api-key' => $apiKey],
				'query' => ['alt' => 'media'],
			],
			'Could not download the generated video from Google.',
			null,
			['x-goog-api-key', 'Authorization'],
		);
	}

	private function downloadXAiVideo(ClientInterface $client, string $videoUrl): string
	{
		if (!HttpHelper::isAllowedXaiUrl($videoUrl)) {
			throw new \RuntimeException('xAI returned an invalid video download URL.');
		}

		return $this->downloadRemoteVideo(
			$client,
			$videoUrl,
			[],
			'Could not download the generated video from xAI.',
			[HttpHelper::class, 'isAllowedXaiUrl'],
			['Authorization'],
		);
	}

	/**
	 * Downloads to a managed video path. Redirects are followed manually: every hop must be
	 * https (and pass $isAllowedUrl), and $sensitiveHeaders are not sent to other hosts.
	 *
	 * @param callable(string): bool|null $isAllowedUrl
	 * @param string[] $sensitiveHeaders
	 */
	private function downloadRemoteVideo(
		ClientInterface $client,
		string $url,
		array $requestOptions,
		string $failureMessage,
		?callable $isAllowedUrl = null,
		array $sensitiveHeaders = [],
	): string {
		$path = $this->createVideoPath();

		try {
			HttpHelper::downloadToFile(
				$client,
				$url,
				$path,
				self::MAX_VIDEO_DOWNLOAD_BYTES,
				array_merge($requestOptions, [
					'connect_timeout' => 30,
					'timeout' => 300,
				]),
				$isAllowedUrl,
				$sensitiveHeaders,
			);
			$this->assertValidMp4($path);

			return $path;
		} catch (\Throwable $e) {
			PluginFileHelper::delete($path);
			throw new \RuntimeException($failureMessage, 0, $e);
		}
	}

	private function writeBase64Video(string $videoData): string
	{
		if (str_contains($videoData, ',')) {
			[, $videoData] = explode(',', $videoData, 2);
		}

		$decodedVideo = base64_decode($videoData, true);
		if ($decodedVideo === false || $decodedVideo === '') {
			throw new \RuntimeException('Google returned invalid base64 video data.');
		}

		$path = $this->createVideoPath();
		if (file_put_contents($path, $decodedVideo) === false) {
			@unlink($path);
			throw new \RuntimeException('Could not save the generated video.');
		}

		try {
			$this->assertValidMp4($path);
		} catch (\Throwable $e) {
			@unlink($path);
			throw $e;
		}

		return $path;
	}

	private function assertValidMp4(string $path): void
	{
		$handle = fopen($path, 'rb');
		$header = $handle !== false ? fread($handle, 12) : false;
		if (is_resource($handle)) {
			fclose($handle);
		}

		if (!is_string($header) || strlen($header) < 12 || substr($header, 4, 4) !== 'ftyp') {
			throw new \RuntimeException('The provider returned an invalid MP4 video.');
		}
	}

	private function extractGoogleFileId(string $uri): ?string
	{
		return preg_match('#(?:^|/)files/([^/:?]+)#', $uri, $matches) === 1
			? $matches[1]
			: null;
	}

	private function decodeJsonResponse(string $body, string $failureMessage): array
	{
		$data = json_decode($body, true);
		if (!is_array($data)) {
			throw new \RuntimeException($failureMessage);
		}

		return $data;
	}

	private function extractErrorMessage(array $data, string $fallback): string
	{
		$message = $data['error']['message'] ?? $data['message'] ?? null;

		return is_string($message) && trim($message) !== '' ? trim($message) : $fallback;
	}

	private function reportProviderProgress(?callable $onProgress, int $attempt, string $label): void
	{
		$progress = min(0.88, 0.45 + (($attempt + 1) * 0.004));
		$this->reportProgress($onProgress, $progress, $label);
	}

	private function createVideoPath(): string
	{
		return $this->getVideoDirectory() . DIRECTORY_SEPARATOR . 'video-' . bin2hex(random_bytes(16)) . '.mp4';
	}

	private function getVideoDirectory(): string
	{
		$directory = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'image-enhancer-videos';
		FileHelper::createDirectory($directory);

		return $directory;
	}

	private function throwIfCanceled(?callable $isCanceled): void
	{
		if ($isCanceled && $isCanceled()) {
			throw new \RuntimeException('Video generation was canceled.');
		}
	}

	private function reportProgress(?callable $onProgress, float $progress, string $label): void
	{
		if ($onProgress) {
			$onProgress($progress, $label);
		}
	}
}
