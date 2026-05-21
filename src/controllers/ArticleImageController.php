<?php

namespace arjanbrinkman\craftimagequalitychecker\controllers;

use arjanbrinkman\craftimagequalitychecker\ImageQualityChecker;
use arjanbrinkman\craftimagequalitychecker\models\Settings;
use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use GuzzleHttp\ClientInterface;
use Imagick;
use yii\web\Response;

class ArticleImageController extends Controller
{
	public function actionEnhance(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$asset = $this->getPostedAsset();
		if (!$asset instanceof Asset) {
			return $this->asFailure('Asset not found or unsupported.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asFailure('You do not have permission to enhance this asset.');
		}

		$settings = ImageQualityChecker::getInstance()->getSettings();
		$apiKey = $settings->chatGptApiKey;

		if (!$apiKey) {
			return $this->asFailure('OpenAI API key is missing.');
		}

		$localPath = $this->getFullAssetPath($asset);
		if (!$localPath || !file_exists($localPath)) {
			return $this->asFailure('Could not find the original asset file.');
		}

		try {
			$tempPath = $this->enhanceToTempFile(Craft::createGuzzleClient(), $settings, $asset, $localPath, $apiKey);
			$previewAsset = $this->createPreviewAsset($asset, $tempPath);

			if (!$previewAsset instanceof Asset) {
				@unlink($tempPath);
				return $this->asFailure('Could not save the enhanced preview asset.');
			}

			return $this->asJson([
				'success' => true,
				'assetId' => $asset->id,
				'previewId' => $previewAsset->id,
				'enhancedUrl' => $this->appendCacheBuster($previewAsset->getUrl()),
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Article image enhancement failed: ' . $e->getMessage(), __METHOD__);
			return $this->asFailure('Enhancement failed: ' . $e->getMessage());
		}
	}

	public function actionKeep(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$asset = $this->getPostedAsset();
		$previewAsset = $this->getPostedPreviewAsset();

		if (!$asset instanceof Asset || !$previewAsset instanceof Asset || !$this->isPreviewAssetForOriginal($previewAsset, $asset)) {
			return $this->asFailure('Enhanced preview asset not found or invalid.');
		}
		if (!$this->canSaveAsset($asset) || !$this->canDeleteAsset($previewAsset)) {
			return $this->asFailure('You do not have permission to keep this enhanced image.');
		}

		$previewPath = $this->getFullAssetPath($previewAsset);

		if (!$previewPath || !file_exists($previewPath)) {
			return $this->asFailure('Could not find the enhanced preview file.');
		}

		$tempPath = $this->getTempReplacementPath($asset);

		try {
			if (!copy($previewPath, $tempPath)) {
				return $this->asFailure('Could not prepare the enhanced file for replacement.');
			}

			$asset->tempFilePath = $tempPath;
			$asset->setScenario(Asset::SCENARIO_REPLACE);

			ImageQualityChecker::$skipAssetQueue = true;
			try {
				$saved = Craft::$app->elements->saveElement($asset);
			} finally {
				ImageQualityChecker::$skipAssetQueue = false;
			}

			if (!$saved) {
				return $this->asFailure('Could not replace the original asset: ' . implode(', ', $asset->getFirstErrors()));
			}

			$this->deleteElement($previewAsset);

			return $this->asJson([
				'success' => true,
				'assetId' => $asset->id,
				'imageUrl' => $this->appendCacheBuster($asset->getUrl()),
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Keeping enhanced article image failed: ' . $e->getMessage(), __METHOD__);
			return $this->asFailure('Could not keep enhanced image: ' . $e->getMessage());
		} finally {
			if (isset($tempPath) && file_exists($tempPath)) {
				@unlink($tempPath);
			}
		}
	}

	public function actionDiscard(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$asset = $this->getPostedAsset();
		$previewAsset = $this->getPostedPreviewAsset();

		if (!$asset instanceof Asset || !$previewAsset instanceof Asset || !$this->isPreviewAssetForOriginal($previewAsset, $asset)) {
			return $this->asFailure('Enhanced preview asset not found or invalid.');
		}
		if (!$this->canDeleteAsset($previewAsset)) {
			return $this->asFailure('You do not have permission to discard this enhanced image.');
		}

		try {
			$this->deleteElement($previewAsset);

			return $this->asJson([
				'success' => true,
				'assetId' => $asset->id,
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Discarding enhanced article image failed: ' . $e->getMessage(), __METHOD__);
			return $this->asFailure('Could not discard enhanced image: ' . $e->getMessage());
		}
	}

	private function getPostedAsset(): ?Asset
	{
		$assetId = (int) Craft::$app->getRequest()->getBodyParam('assetId');
		if (!$assetId) {
			return null;
		}

		$asset = Craft::$app->assets->getAssetById($assetId);
		if (!$this->isSupportedImageAsset($asset)) {
			return null;
		}

		return $asset;
	}

	private function getPostedPreviewAsset(): ?Asset
	{
		$previewId = (int) Craft::$app->getRequest()->getBodyParam('previewId');
		if (!$previewId) {
			return null;
		}

		$asset = Craft::$app->assets->getAssetById($previewId);
		if (!$this->isSupportedImageAsset($asset)) {
			return null;
		}

		return $asset;
	}

	private function isSupportedImageAsset(?Asset $asset): bool
	{
		return $asset instanceof Asset &&
			$asset->kind === 'image' &&
			in_array($asset->mimeType, ['image/jpeg', 'image/jpg', 'image/png'], true);
	}

	private function canSaveAsset(Asset $asset): bool
	{
		$user = Craft::$app->getUser()->getIdentity();

		return $user && $asset->canSave($user);
	}

	private function canDeleteAsset(Asset $asset): bool
	{
		$user = Craft::$app->getUser()->getIdentity();

		return $user && $asset->canDelete($user);
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
		$previewAsset->uploaderId = Craft::$app->getUser()->getId() ?: $originalAsset->uploaderId;
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

	private function deleteElement(Asset $asset): void
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

	private function isPreviewAssetForOriginal(Asset $previewAsset, Asset $originalAsset): bool
	{
		$originalBaseName = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($originalAsset->filename, PATHINFO_FILENAME)) ?: 'image';

		return $previewAsset->id !== $originalAsset->id &&
			$previewAsset->volumeId === $originalAsset->volumeId &&
			$previewAsset->folderId === $originalAsset->folderId &&
			str_starts_with($previewAsset->filename, $originalBaseName . '-enhancement-preview-');
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

	private function asFailure(string $message): Response
	{
		return $this->asJson([
			'success' => false,
			'message' => $message,
		]);
	}
}
