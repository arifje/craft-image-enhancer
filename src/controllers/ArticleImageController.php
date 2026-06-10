<?php

namespace arjanbrinkman\craftimagequalitychecker\controllers;

use arjanbrinkman\craftimagequalitychecker\ImageQualityChecker;
use arjanbrinkman\craftimagequalitychecker\jobs\ArticleImageEnhancementJob;
use arjanbrinkman\craftimagequalitychecker\models\Settings;
use Craft;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use craft\web\Controller;
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
			return $this->asJsonFailure('Asset not found or unsupported.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to enhance this asset.');
		}

		$settings = ImageQualityChecker::getInstance()->getSettings();
		$enhancementService = ImageQualityChecker::getInstance()->aiImageEnhancement;
		$providerOptions = $this->getProviderOptionsForRequest($settings);
		if ($providerOptions === false) {
			return $this->asJsonFailure('Invalid AI image provider or model.');
		}
		if ($enhancementService->getConfiguredApiKey($settings, $providerOptions) === '') {
			return $this->asJsonFailure($enhancementService->getProviderLabel($settings, $providerOptions) . ' API key is missing.');
		}

		$localPath = $this->getFullAssetPath($asset);
		if (!$localPath || !file_exists($localPath)) {
			return $this->asJsonFailure('Could not find the original asset file.');
		}

		try {
			$token = bin2hex(random_bytes(16));
			$this->setEnhancementStatus($token, [
				'status' => 'queued',
				'assetId' => $asset->id,
				'progress' => 0,
				'progressLabel' => 'Queued',
			]);
			$jobId = Craft::$app->queue->push(new ArticleImageEnhancementJob([
				'assetId' => $asset->id,
				'userId' => Craft::$app->getUser()->getId(),
				'token' => $token,
				'imageEnhancementProvider' => $providerOptions['provider'] ?? null,
				'imageEnhancementModel' => $providerOptions['model'] ?? null,
			]));
			$this->setEnhancementStatus($token, [
				'status' => 'queued',
				'assetId' => $asset->id,
				'jobId' => $jobId,
				'progress' => 0,
				'progressLabel' => 'Queued',
			]);

			return $this->asJson([
				'success' => true,
				'queued' => true,
				'assetId' => $asset->id,
				'jobId' => $jobId,
				'token' => $token,
				'statusUrl' => UrlHelper::actionUrl('_image-quality-checker/article-image/status'),
				'imageEnhancementProvider' => $providerOptions['provider'] ?? $settings->imageEnhancementProvider,
				'imageEnhancementModel' => $providerOptions['model'] ?? $enhancementService->getProviderModel($settings, $providerOptions),
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Article image enhancement queueing failed: ' . $e->getMessage(), __METHOD__);
			return $this->asJsonFailure('Could not queue enhancement: ' . $e->getMessage());
		}
	}

	private function getProviderOptionsForRequest(Settings $settings): array|false
	{
		if ($settings->imageEnhancementProvider !== Settings::IMAGE_PROVIDER_FRONTEND) {
			return [];
		}

		$request = Craft::$app->getRequest();
		$provider = (string) $request->getBodyParam('imageEnhancementProvider');
		$model = (string) $request->getBodyParam('imageEnhancementModel');
		$modelsByProvider = [
			Settings::IMAGE_PROVIDER_OPENAI => array_column(Settings::imageEnhancementModelOptions(), 'value'),
			Settings::IMAGE_PROVIDER_XAI => array_column(Settings::xAiImageEnhancementModelOptions(), 'value'),
			Settings::IMAGE_PROVIDER_GOOGLE => array_column(Settings::googleImageEnhancementModelOptions(), 'value'),
		];

		if (!isset($modelsByProvider[$provider])) {
			return false;
		}

		if (!in_array($model, $modelsByProvider[$provider], true)) {
			return false;
		}

		return [
			'provider' => $provider,
			'model' => $model,
		];
	}

	public function actionStatus(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$assetId = (int) Craft::$app->getRequest()->getBodyParam('assetId');
		$token = (string) Craft::$app->getRequest()->getBodyParam('token');

		if (!$assetId) {
			return $this->asJsonFailure('Missing enhancement asset.');
		}

		$asset = Craft::$app->assets->getAssetById($assetId);
		if (!$this->isSupportedImageAsset($asset) || !$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to view this enhancement status.');
		}

		$status = $token !== ''
			? Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token))
			: Craft::$app->getCache()->get($this->getEnhancementAssetStatusCacheKey($assetId));
		if (!is_array($status)) {
			return $this->asJson([
				'success' => true,
				'status' => $token !== '' ? 'pending' : 'idle',
				'assetId' => $assetId,
				'progress' => 0,
				'progressLabel' => $token !== '' ? 'Waiting for queue' : 'Idle',
			]);
		}

		if ((int) ($status['assetId'] ?? 0) !== $assetId) {
			return $this->asJsonFailure('Enhancement status token does not match this asset.');
		}

		return $this->asJson(array_merge(['success' => true], $status));
	}

	public function actionCancel(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$asset = $this->getPostedAsset();
		$token = (string) Craft::$app->getRequest()->getBodyParam('token');
		$jobId = (string) Craft::$app->getRequest()->getBodyParam('jobId');

		if (!$asset instanceof Asset || !$token) {
			return $this->asJsonFailure('Missing enhancement cancellation details.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to cancel this enhancement.');
		}

		$status = Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token));
		if (is_array($status) && (int) ($status['assetId'] ?? 0) !== (int) $asset->id) {
			return $this->asJsonFailure('Enhancement status token does not match this asset.');
		}

		$existingStatus = is_array($status) ? $status : [];
		$this->setEnhancementStatus($token, array_merge($existingStatus, [
			'status' => 'canceled',
			'assetId' => $asset->id,
			'jobId' => $jobId !== '' ? $jobId : ($existingStatus['jobId'] ?? null),
			'progress' => 1,
			'progressLabel' => 'Canceled',
		]));

		if (!empty($existingStatus['previewId'])) {
			$previewAsset = Craft::$app->assets->getAssetById((int) $existingStatus['previewId']);
			if (
				$previewAsset instanceof Asset &&
				$this->isPreviewAssetForOriginal($previewAsset, $asset, $token)
			) {
				$this->deleteElement($previewAsset);
			}
		}

		$released = false;
		if ($jobId !== '') {
			try {
				Craft::$app->queue->release($jobId);
				$released = true;
			} catch (\Throwable $e) {
				Craft::warning('ImageQualityChecker: Could not release canceled article image enhancement job: ' . $e->getMessage(), __METHOD__);
			}
		}

		return $this->asJson([
			'success' => true,
			'status' => 'canceled',
			'assetId' => $asset->id,
			'token' => $token,
			'released' => $released,
		]);
	}

	public function actionKeep(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$asset = $this->getPostedAsset();
		$previewAsset = $this->getPostedPreviewAsset();
		$token = (string) Craft::$app->getRequest()->getBodyParam('token');

		if (!$asset instanceof Asset || !$previewAsset instanceof Asset || !$this->isPreviewAssetForOriginal($previewAsset, $asset, $token)) {
			return $this->asJsonFailure('Enhanced preview asset not found or invalid.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to keep this enhanced image.');
		}

		$previewPath = $this->getFullAssetPath($previewAsset);

		if (!$previewPath || !file_exists($previewPath)) {
			return $this->asJsonFailure('Could not find the enhanced preview file.');
		}

		$tempPath = $this->getTempReplacementPath($asset);

		try {
			if (!copy($previewPath, $tempPath)) {
				return $this->asJsonFailure('Could not prepare the enhanced file for replacement.');
			}

			ImageQualityChecker::$skipAssetQueue = true;
			try {
				Craft::$app->assets->replaceAssetFile($asset, $tempPath, $asset->filename);
			} finally {
				ImageQualityChecker::$skipAssetQueue = false;
			}

			$this->deleteElement($previewAsset);
			$this->deleteEnhancementStatus($token);

			return $this->asJson([
				'success' => true,
				'assetId' => $asset->id,
				'imageUrl' => $this->appendCacheBuster($asset->getUrl()),
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Keeping enhanced article image failed: ' . $e->getMessage(), __METHOD__);
			return $this->asJsonFailure('Could not keep enhanced image: ' . $e->getMessage());
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
		$token = (string) Craft::$app->getRequest()->getBodyParam('token');

		if (!$asset instanceof Asset || !$previewAsset instanceof Asset || !$this->isPreviewAssetForOriginal($previewAsset, $asset, $token)) {
			return $this->asJsonFailure('Enhanced preview asset not found or invalid.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to discard this enhanced image.');
		}

		try {
			$this->deleteElement($previewAsset);
			$this->deleteEnhancementStatus($token);

			return $this->asJson([
				'success' => true,
				'assetId' => $asset->id,
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Discarding enhanced article image failed: ' . $e->getMessage(), __METHOD__);
			return $this->asJsonFailure('Could not discard enhanced image: ' . $e->getMessage());
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

	private function deleteElement(Asset $asset): void
	{
		ImageQualityChecker::$skipAssetQueue = true;
		try {
			Craft::$app->elements->deleteElement($asset);
		} finally {
			ImageQualityChecker::$skipAssetQueue = false;
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

	private function isPreviewAssetForOriginal(Asset $previewAsset, Asset $originalAsset, ?string $token = null): bool
	{
		if ($token) {
			$status = Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token));
			if (
				is_array($status) &&
				(int) ($status['assetId'] ?? 0) === (int) $originalAsset->id &&
				(int) ($status['previewId'] ?? 0) === (int) $previewAsset->id
			) {
				return true;
			}
		}

		$originalBaseName = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($originalAsset->filename, PATHINFO_FILENAME)) ?: 'image';

		return $previewAsset->id !== $originalAsset->id &&
			$previewAsset->volumeId === $originalAsset->volumeId &&
			(
				$previewAsset->folderId === $originalAsset->folderId ||
				str_contains($previewAsset->filename, '-enhancement-preview-')
			) &&
			(
				str_starts_with($previewAsset->filename, $originalBaseName . '-enhancement-preview-') ||
				str_contains($previewAsset->filename, '-enhancement-preview-')
			);
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

	private function setEnhancementStatus(string $token, array $status): void
	{
		$statusWithToken = array_merge($status, ['token' => $token]);

		Craft::$app->getCache()->set($this->getEnhancementStatusCacheKey($token), $statusWithToken, 3600);

		$assetId = (int) ($status['assetId'] ?? 0);
		if ($assetId) {
			Craft::$app->getCache()->set($this->getEnhancementAssetStatusCacheKey($assetId), $statusWithToken, 3600);
		}
	}

	private function deleteEnhancementStatus(?string $token): void
	{
		if ($token) {
			$status = Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token));
			Craft::$app->getCache()->delete($this->getEnhancementStatusCacheKey($token));

			$assetId = is_array($status) ? (int) ($status['assetId'] ?? 0) : 0;
			if ($assetId) {
				Craft::$app->getCache()->delete($this->getEnhancementAssetStatusCacheKey($assetId));
			}
		}
	}

	private function getEnhancementStatusCacheKey(string $token): string
	{
		return 'image-quality-checker:article-image-enhancement:' . $token;
	}

	private function getEnhancementAssetStatusCacheKey(int $assetId): string
	{
		return 'image-quality-checker:article-image-enhancement-asset:' . $assetId;
	}

	private function asJsonFailure(string $message): Response
	{
		return $this->asJson([
			'success' => false,
			'message' => $message,
		]);
	}
}
