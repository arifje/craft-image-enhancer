<?php

namespace arjanbrinkman\craftimageenhancer\controllers;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\jobs\ArticleImageEnhancementJob;
use arjanbrinkman\craftimageenhancer\jobs\ArticleImageFaceBlurJob;
use arjanbrinkman\craftimageenhancer\jobs\ArticleImageVideoJob;
use arjanbrinkman\craftimageenhancer\models\Settings;
use arjanbrinkman\craftimageenhancer\services\AiVideoGenerationService;
use Craft;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
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

		$settings = ImageEnhancer::getInstance()->getSettings();
		$enhancementService = ImageEnhancer::getInstance()->aiImageEnhancement;
		$customPrompt = $this->getCustomEnhancementPromptForRequest();
		if ($customPrompt === false) {
			return $this->asJsonFailure('Enter a custom edit prompt of no more than 4000 characters.');
		}
		$operation = $customPrompt !== null ? 'customEnhance' : 'enhance';
		$repairToken = (string) Craft::$app->getRequest()->getParam('uploadRepairToken');
		if ($repairToken !== '' && $customPrompt !== null) {
			return $this->asJsonFailure('Custom edits are not available while repairing an invalid upload.');
		}
		$repairTarget = null;
		if ($repairToken !== '') {
			$repairTarget = ImageEnhancer::getInstance()->assetRequirements->getRepairTargetDimensions(
				$repairToken,
				(int) $asset->id,
				(int) Craft::$app->getUser()->getId(),
			);
			if ($repairTarget === null) {
				return $this->asJsonFailure('This upload repair session is invalid or has expired.');
			}
		}
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
				'operation' => $operation,
				'progress' => 0,
				'progressLabel' => 'Queued',
			]);
			$jobId = Craft::$app->queue->push(new ArticleImageEnhancementJob([
				'assetId' => $asset->id,
				'userId' => Craft::$app->getUser()->getId(),
				'token' => $token,
				'imageEnhancementProvider' => $providerOptions['provider'] ?? null,
				'imageEnhancementModel' => $providerOptions['model'] ?? null,
				'customPrompt' => $customPrompt,
				'targetWidth' => $repairTarget['width'] ?? null,
				'targetHeight' => $repairTarget['height'] ?? null,
			]));
			$this->setEnhancementStatus($token, [
				'status' => 'queued',
				'assetId' => $asset->id,
				'operation' => $operation,
				'jobId' => $jobId,
				'progress' => 0,
				'progressLabel' => 'Queued',
			]);

			return $this->asJson([
				'success' => true,
				'queued' => true,
				'assetId' => $asset->id,
				'operation' => $operation,
				'jobId' => $jobId,
				'token' => $token,
				'statusUrl' => UrlHelper::actionUrl('craft-image-enhancer/article-image/status'),
				'imageEnhancementProvider' => $providerOptions['provider'] ?? $settings->imageEnhancementProvider,
				'imageEnhancementModel' => $providerOptions['model'] ?? $enhancementService->getProviderModel($settings, $providerOptions),
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Article image enhancement queueing failed: ' . $e->getMessage(), __METHOD__);
			return $this->asJsonFailure('Could not queue enhancement: ' . $e->getMessage());
		}
	}

	private function getCustomEnhancementPromptForRequest(): string|false|null
	{
		$value = Craft::$app->getRequest()->getBodyParam('customPrompt');
		if ($value === null) {
			return null;
		}
		if (!is_string($value)) {
			return false;
		}

		$prompt = trim($value);
		$length = function_exists('mb_strlen') ? mb_strlen($prompt) : strlen($prompt);

		return $prompt !== '' && $length <= 4000 ? $prompt : false;
	}

	public function actionCreateVideo(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$asset = $this->getPostedAsset();
		if (!$asset instanceof Asset) {
			return $this->asJsonFailure('Asset not found or unsupported.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to create a video from this asset.');
		}
		if ((string) Craft::$app->getRequest()->getBodyParam('uploadRepairToken') !== '') {
			return $this->asJsonFailure('Create Video is not available while repairing an invalid upload.');
		}

		$videoPrompt = $this->getVideoPromptForRequest();
		if ($videoPrompt === false) {
			return $this->asJsonFailure('Enter video instructions of no more than 4000 characters.');
		}

		$settings = ImageEnhancer::getInstance()->getSettings();
		$videoService = ImageEnhancer::getInstance()->aiVideoGeneration;
		$videoProvider = Craft::$app->getRequest()->getBodyParam('videoProvider');
		$videoModel = Craft::$app->getRequest()->getBodyParam('videoModel');
		if (
			($videoProvider !== null && !is_string($videoProvider)) ||
			($videoModel !== null && !is_string($videoModel))
		) {
			return $this->asJsonFailure('Invalid video provider or model.');
		}
		$providerOptions = $videoService->resolveProviderOptions(
			$videoProvider,
			$videoModel,
		);
		if ($providerOptions === false) {
			return $this->asJsonFailure('Invalid video provider or model.');
		}
		if ($videoService->getConfiguredApiKey($settings, $providerOptions['provider']) === '') {
			return $this->asJsonFailure(
				$videoService->getProviderLabel($providerOptions['provider']) . ' API key is missing. Add it in the Image Enhancer settings first.',
			);
		}

		$localPath = $this->getFullAssetPath($asset);
		if (!$localPath || !file_exists($localPath)) {
			return $this->asJsonFailure('Could not find the source image file.');
		}

		try {
			$videoService->cleanupExpiredVideos();
			$token = bin2hex(random_bytes(16));
			$this->setEnhancementStatus($token, [
				'status' => 'queued',
				'assetId' => $asset->id,
				'operation' => 'createVideo',
				'videoProvider' => $providerOptions['provider'],
				'videoModel' => $providerOptions['model'],
				'progress' => 0,
				'progressLabel' => 'Queued',
			]);
			$jobId = Craft::$app->queue->push(new ArticleImageVideoJob([
				'assetId' => $asset->id,
				'userId' => Craft::$app->getUser()->getId(),
				'token' => $token,
				'videoPrompt' => $videoPrompt,
				'videoProvider' => $providerOptions['provider'],
				'videoModel' => $providerOptions['model'],
			]));
			$this->setEnhancementStatus($token, [
				'status' => 'queued',
				'assetId' => $asset->id,
				'operation' => 'createVideo',
				'videoProvider' => $providerOptions['provider'],
				'videoModel' => $providerOptions['model'],
				'jobId' => $jobId,
				'progress' => 0,
				'progressLabel' => 'Queued',
			]);

			return $this->asJson([
				'success' => true,
				'queued' => true,
				'assetId' => $asset->id,
				'operation' => 'createVideo',
				'jobId' => $jobId,
				'token' => $token,
				'statusUrl' => UrlHelper::actionUrl('craft-image-enhancer/article-image/status'),
				'videoProvider' => $providerOptions['provider'],
				'videoModel' => $providerOptions['model'],
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Article image video queueing failed: ' . $e->getMessage(), __METHOD__);
			return $this->asJsonFailure('Could not queue video generation: ' . $e->getMessage());
		}
	}

	private function getVideoPromptForRequest(): string|false
	{
		$value = Craft::$app->getRequest()->getBodyParam('videoPrompt', '');
		if (!is_string($value)) {
			return false;
		}

		$prompt = trim($value);
		$length = function_exists('mb_strlen') ? mb_strlen($prompt) : strlen($prompt);

		return $length <= AiVideoGenerationService::MAX_PROMPT_LENGTH ? $prompt : false;
	}

	public function actionBlurFaces(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$asset = $this->getPostedAsset();
		if (!$asset instanceof Asset) {
			return $this->asJsonFailure('Asset not found or unsupported.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to blur faces in this asset.');
		}
		if (!class_exists(\Imagick::class)) {
			return $this->asJsonFailure('Imagick is required to blur faces.');
		}

		$manualFaces = $this->getManualBlurFacesForRequest();
		if ($manualFaces === false) {
			return $this->asJsonFailure('Manual blur areas are invalid.');
		}
		$useManualFaces = is_array($manualFaces) && !empty($manualFaces);

		$settings = ImageEnhancer::getInstance()->getSettings();
		if (!$useManualFaces && $settings->getResolvedChatGptApiKey() === '') {
			return $this->asJsonFailure('ChatGPT API key is missing.');
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
				'operation' => 'blurFaces',
				'blurMode' => $useManualFaces ? 'manual' : 'auto',
				'manualFaceCount' => $useManualFaces ? count($manualFaces) : 0,
				'progress' => 0,
				'progressLabel' => 'Queued',
			]);
			$jobId = Craft::$app->queue->push(new ArticleImageFaceBlurJob([
				'assetId' => $asset->id,
				'userId' => Craft::$app->getUser()->getId(),
				'token' => $token,
				'useManualFaces' => $useManualFaces,
				'manualFaces' => $manualFaces ?: [],
			]));
			$this->setEnhancementStatus($token, [
				'status' => 'queued',
				'assetId' => $asset->id,
				'operation' => 'blurFaces',
				'blurMode' => $useManualFaces ? 'manual' : 'auto',
				'manualFaceCount' => $useManualFaces ? count($manualFaces) : 0,
				'jobId' => $jobId,
				'progress' => 0,
				'progressLabel' => 'Queued',
			]);

			return $this->asJson([
				'success' => true,
				'queued' => true,
				'assetId' => $asset->id,
				'operation' => 'blurFaces',
				'jobId' => $jobId,
				'token' => $token,
				'statusUrl' => UrlHelper::actionUrl('craft-image-enhancer/article-image/status'),
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Article image face blur queueing failed: ' . $e->getMessage(), __METHOD__);
			return $this->asJsonFailure('Could not queue face blur: ' . $e->getMessage());
		}
	}

	private function getManualBlurFacesForRequest(): array|false|null
	{
		$value = Craft::$app->getRequest()->getBodyParam('manualFaces');

		if ($value === null || $value === '') {
			return null;
		}

		if (is_string($value)) {
			$value = json_decode($value, true);
			if (!is_array($value)) {
				return false;
			}
		}

		if (isset($value['faces']) && is_array($value['faces'])) {
			$value = $value['faces'];
		}

		if (!is_array($value)) {
			return false;
		}

		$faces = [];
		foreach ($value as $face) {
			if (!is_array($face)) {
				return false;
			}

			$x = $this->normalizeBlurCoordinate($face['x'] ?? null);
			$y = $this->normalizeBlurCoordinate($face['y'] ?? null);
			$width = $this->normalizeBlurCoordinate($face['width'] ?? null);
			$height = $this->normalizeBlurCoordinate($face['height'] ?? null);

			if ($x === null || $y === null || $width === null || $height === null) {
				return false;
			}

			$width = min(1000 - $x, $width);
			$height = min(1000 - $y, $height);

			if ($width < 5 || $height < 5) {
				return false;
			}

			$faces[] = [
				'x' => $x,
				'y' => $y,
				'width' => $width,
				'height' => $height,
				'confidence' => 'manual',
				'source' => 'manual',
			];
		}

		return !empty($faces) ? $faces : false;
	}

	private function normalizeBlurCoordinate(mixed $value): ?int
	{
		if (!is_numeric($value)) {
			return null;
		}

		return max(0, min(1000, (int) round((float) $value)));
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
			Settings::IMAGE_PROVIDER_OPENAI => array_column(ImageEnhancer::getInstance()->getImageEnhancementModelOptions(), 'value'),
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

	public function actionAssetInfo(): Response
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

		return $this->asJson([
			'success' => true,
			'assetId' => $asset->id,
			'url' => $this->appendCacheBuster($asset->getUrl()),
			'filename' => $asset->filename,
			'width' => $asset->width,
			'height' => $asset->height,
		]);
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

		$previewId = (int) ($status['previewId'] ?? 0);
		if (($status['status'] ?? null) === 'complete' && $previewId) {
			$previewAsset = Craft::$app->assets->getAssetById($previewId);
			if (
				$previewAsset instanceof Asset &&
				$this->isPreviewAssetForOriginal($previewAsset, $asset, (string) ($status['token'] ?? $token))
			) {
				$status['enhancedUrl'] = UrlHelper::actionUrl('craft-image-enhancer/article-image/preview', [
					'assetId' => $assetId,
					'previewId' => $previewId,
					'token' => (string) ($status['token'] ?? $token),
					'uploadRepairToken' => (string) Craft::$app->getRequest()->getBodyParam('uploadRepairToken'),
					'v' => time(),
				]);
			}
		}

		if (($status['operation'] ?? null) === 'createVideo' && ($status['status'] ?? null) === 'complete') {
			$videoPath = is_string($status['videoPath'] ?? null) ? $status['videoPath'] : null;
			if (ImageEnhancer::getInstance()->aiVideoGeneration->isManagedVideoPath($videoPath)) {
				$status['downloadUrl'] = UrlHelper::actionUrl('craft-image-enhancer/article-image/download-video', [
					'assetId' => $assetId,
					'token' => (string) ($status['token'] ?? $token),
				]);
			} else {
				$status['status'] = 'failed';
				$status['progressLabel'] = 'Video download expired';
				$status['message'] = 'The generated video is no longer available. Create it again.';
			}
		}

		unset($status['videoPath']);

		return $this->asJson(array_merge(['success' => true], $status));
	}

	public function actionPreview(): Response
	{
		$this->requireLogin();

		$request = Craft::$app->getRequest();
		$asset = Craft::$app->assets->getAssetById((int) $request->getQueryParam('assetId'));
		$previewAsset = Craft::$app->assets->getAssetById((int) $request->getQueryParam('previewId'));
		$token = (string) $request->getQueryParam('token');

		if (
			!$this->isSupportedImageAsset($asset) ||
			!$this->isSupportedImageAsset($previewAsset) ||
			$token === '' ||
			!$this->canSaveAsset($asset) ||
			!$this->isPreviewAssetForOriginal($previewAsset, $asset, $token)
		) {
			throw new NotFoundHttpException('Enhanced preview not found.');
		}

		$response = Craft::$app->getResponse();
		$response->format = Response::FORMAT_RAW;
		$response->headers->set('Content-Type', $previewAsset->mimeType ?: 'application/octet-stream');
		$response->headers->set('Cache-Control', 'private, no-store, max-age=0');
		$response->content = $previewAsset->getContents();

		return $response;
	}

	public function actionDownloadVideo(): Response
	{
		$this->requireLogin();

		$request = Craft::$app->getRequest();
		$assetId = (int) $request->getQueryParam('assetId');
		$token = (string) $request->getQueryParam('token');
		$asset = Craft::$app->assets->getAssetById($assetId);
		$status = $token !== ''
			? Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token))
			: null;

		if (
			!$this->isSupportedImageAsset($asset) ||
			!$this->canSaveAsset($asset) ||
			!is_array($status) ||
			(int) ($status['assetId'] ?? 0) !== $assetId ||
			($status['operation'] ?? null) !== 'createVideo' ||
			($status['status'] ?? null) !== 'complete'
		) {
			throw new NotFoundHttpException('Generated video not found.');
		}

		$videoPath = is_string($status['videoPath'] ?? null) ? $status['videoPath'] : null;
		if (!ImageEnhancer::getInstance()->aiVideoGeneration->isManagedVideoPath($videoPath)) {
			throw new NotFoundHttpException('Generated video not found.');
		}

		$filename = is_string($status['videoFilename'] ?? null) && $status['videoFilename'] !== ''
			? $status['videoFilename']
			: ImageEnhancer::getInstance()->aiVideoGeneration->getDownloadFilename($asset);
		$response = Craft::$app->getResponse()->sendFile((string) $videoPath, $filename, [
			'mimeType' => 'video/mp4',
			'inline' => false,
		]);
		$response->headers->set('Cache-Control', 'private, no-store, max-age=0');

		return $response;
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
		if (isset($existingStatus['videoPath'])) {
			ImageEnhancer::getInstance()->aiVideoGeneration->deleteVideo(
				is_string($existingStatus['videoPath']) ? $existingStatus['videoPath'] : null,
			);
			unset($existingStatus['videoPath'], $existingStatus['videoFilename']);
		}
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
				Craft::warning('ImageEnhancer: Could not release canceled article image enhancement job: ' . $e->getMessage(), __METHOD__);
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

	public function actionReset(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$asset = $this->getPostedAsset();
		$token = (string) Craft::$app->getRequest()->getBodyParam('token');

		if (!$asset instanceof Asset) {
			return $this->asJsonFailure('Missing enhancement asset.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to reset this enhancement status.');
		}

		$status = $token !== ''
			? Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token))
			: Craft::$app->getCache()->get($this->getEnhancementAssetStatusCacheKey((int) $asset->id));

		if (is_array($status) && (int) ($status['assetId'] ?? 0) !== (int) $asset->id) {
			return $this->asJsonFailure('Enhancement status token does not match this asset.');
		}

		$this->deleteEnhancementStatus($token !== '' ? $token : ($status['token'] ?? null), (int) $asset->id);

		return $this->asJson([
			'success' => true,
			'status' => 'idle',
			'assetId' => $asset->id,
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

			ImageEnhancer::$skipAssetQueue = true;
			try {
				Craft::$app->assets->replaceAssetFile($asset, $tempPath, $asset->filename);
			} finally {
				ImageEnhancer::$skipAssetQueue = false;
			}

			clearstatcache(true, $this->getFullAssetPath($asset) ?: '');
			$updatedAsset = Craft::$app->assets->getAssetById((int) $asset->id) ?: $asset;

			$this->deleteElement($previewAsset);
			$this->deleteEnhancementStatus($token);

			return $this->asJson([
				'success' => true,
				'assetId' => $updatedAsset->id,
				'imageUrl' => $this->appendCacheBuster($updatedAsset->getUrl()),
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Keeping enhanced article image failed: ' . $e->getMessage(), __METHOD__);
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
			Craft::error('ImageEnhancer: Discarding enhanced article image failed: ' . $e->getMessage(), __METHOD__);
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

		if ($user && $asset->canSave($user)) {
			return true;
		}

		$repairToken = (string) Craft::$app->getRequest()->getParam('uploadRepairToken');

		return $user &&
			$repairToken !== '' &&
			ImageEnhancer::getInstance()->assetRequirements->getAuthorizedRepairContext(
				$repairToken,
				(int) $user->id,
				(int) $asset->id,
			) !== null;
	}

	private function deleteElement(Asset $asset): void
	{
		ImageEnhancer::$skipAssetQueue = true;
		try {
			Craft::$app->elements->deleteElement($asset);
		} finally {
			ImageEnhancer::$skipAssetQueue = false;
		}
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

		$previewFilename = $previewAsset->filename;
		$hasPreviewMarker = str_contains($previewFilename, '-enhancement-preview-') ||
			str_contains($previewFilename, '-face-blur-preview-');

		return $previewAsset->id !== $originalAsset->id &&
			$previewAsset->volumeId === $originalAsset->volumeId &&
			(
				$previewAsset->folderId === $originalAsset->folderId ||
				$hasPreviewMarker
			) &&
			(
				str_starts_with($previewFilename, $originalBaseName . '-enhancement-preview-') ||
				str_starts_with($previewFilename, $originalBaseName . '-face-blur-preview-') ||
				$hasPreviewMarker
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

	private function deleteEnhancementStatus(?string $token, ?int $assetId = null): void
	{
		if ($token) {
			$status = Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token));
			if (is_array($status) && isset($status['videoPath'])) {
				ImageEnhancer::getInstance()->aiVideoGeneration->deleteVideo(
					is_string($status['videoPath']) ? $status['videoPath'] : null,
				);
			}
			Craft::$app->getCache()->delete($this->getEnhancementStatusCacheKey($token));

			$assetId = $assetId ?: (is_array($status) ? (int) ($status['assetId'] ?? 0) : 0);
		}

		if ($assetId) {
			Craft::$app->getCache()->delete($this->getEnhancementAssetStatusCacheKey($assetId));
		}
	}

	private function getEnhancementStatusCacheKey(string $token): string
	{
		return 'image-enhancer:article-image-enhancement:' . $token;
	}

	private function getEnhancementAssetStatusCacheKey(int $assetId): string
	{
		return 'image-enhancer:article-image-enhancement-asset:' . $assetId;
	}

	private function asJsonFailure(string $message): Response
	{
		return $this->asJson([
			'success' => false,
			'message' => $message,
		]);
	}
}
