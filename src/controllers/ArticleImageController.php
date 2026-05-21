<?php

namespace arjanbrinkman\craftimagequalitychecker\controllers;

use arjanbrinkman\craftimagequalitychecker\ImageQualityChecker;
use arjanbrinkman\craftimagequalitychecker\jobs\ArticleImageEnhancementJob;
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

		if (!ImageQualityChecker::getInstance()->getSettings()->chatGptApiKey) {
			return $this->asJsonFailure('OpenAI API key is missing.');
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
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Article image enhancement queueing failed: ' . $e->getMessage(), __METHOD__);
			return $this->asJsonFailure('Could not queue enhancement: ' . $e->getMessage());
		}
	}

	public function actionStatus(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$assetId = (int) Craft::$app->getRequest()->getBodyParam('assetId');
		$token = (string) Craft::$app->getRequest()->getBodyParam('token');

		if (!$assetId || !$token) {
			return $this->asJsonFailure('Missing enhancement status token.');
		}

		$status = Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token));
		if (!is_array($status)) {
			return $this->asJson([
				'success' => true,
				'status' => 'pending',
				'assetId' => $assetId,
				'progress' => 0,
				'progressLabel' => 'Waiting for queue',
			]);
		}

		if ((int) ($status['assetId'] ?? 0) !== $assetId) {
			return $this->asJsonFailure('Enhancement status token does not match this asset.');
		}

		return $this->asJson(array_merge(['success' => true], $status));
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
		if (!$this->canSaveAsset($asset) || !$this->canDeleteAsset($previewAsset)) {
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

			$asset->tempFilePath = $tempPath;
			$asset->setScenario(Asset::SCENARIO_REPLACE);

			ImageQualityChecker::$skipAssetQueue = true;
			try {
				$saved = Craft::$app->elements->saveElement($asset);
			} finally {
				ImageQualityChecker::$skipAssetQueue = false;
			}

			if (!$saved) {
				return $this->asJsonFailure('Could not replace the original asset: ' . implode(', ', $asset->getFirstErrors()));
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
		if (!$this->canDeleteAsset($previewAsset)) {
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

	private function canDeleteAsset(Asset $asset): bool
	{
		$user = Craft::$app->getUser()->getIdentity();

		return $user && $asset->canDelete($user);
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
		Craft::$app->getCache()->set($this->getEnhancementStatusCacheKey($token), $status, 3600);
	}

	private function deleteEnhancementStatus(?string $token): void
	{
		if ($token) {
			Craft::$app->getCache()->delete($this->getEnhancementStatusCacheKey($token));
		}
	}

	private function getEnhancementStatusCacheKey(string $token): string
	{
		return 'image-quality-checker:article-image-enhancement:' . $token;
	}

	private function asJsonFailure(string $message): Response
	{
		return $this->asJson([
			'success' => false,
			'message' => $message,
		]);
	}
}
