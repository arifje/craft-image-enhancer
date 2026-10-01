<?php

namespace arjanbrinkman\craftimageenhancer\controllers;

use arjanbrinkman\craftimageenhancer\helpers\EditorImageHelper;
use arjanbrinkman\craftimageenhancer\helpers\FileHelper as EditorFileHelper;
use arjanbrinkman\craftimageenhancer\helpers\ImageHelper;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\jobs\ArticleImageEnhancementJob;
use arjanbrinkman\craftimageenhancer\jobs\ArticleImageFaceBlurJob;
use arjanbrinkman\craftimageenhancer\jobs\ArticleImageVideoJob;
use arjanbrinkman\craftimageenhancer\models\Settings;
use arjanbrinkman\craftimageenhancer\services\AiVideoGenerationService;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

class ArticleImageController extends Controller
{
	/**
	 * Image editing and provider actions share the same CP permission, so gate the whole
	 * controller on CP requests plus the plugin permission. Admins pass automatically.
	 *
	 * @inheritdoc
	 * @throws \yii\web\BadRequestHttpException
	 * @throws \yii\web\ForbiddenHttpException
	 */
	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) {
			return false;
		}

		$this->requireCpRequest();
		$this->requirePermission(ImageEnhancer::PERMISSION_USE_AI_TOOLS);

		return true;
	}

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
				$this->getCurrentUserId(),
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

		if (!$this->assetFileExists($asset)) {
			return $this->asJsonFailure('Could not find the original asset file.');
		}

		$userId = $this->getCurrentUserId();

		return $this->startOperation(
			$asset,
			'enhancement',
			['operation' => $operation],
			static fn(string $token) => new ArticleImageEnhancementJob([
				'assetId' => $asset->id,
				'userId' => $userId,
				'token' => $token,
				'imageEnhancementProvider' => $providerOptions['provider'] ?? null,
				'imageEnhancementModel' => $providerOptions['model'] ?? null,
				'customPrompt' => $customPrompt,
				'targetWidth' => $repairTarget['width'] ?? null,
				'targetHeight' => $repairTarget['height'] ?? null,
			]),
			[
				'imageEnhancementProvider' => $providerOptions['provider'] ?? $settings->imageEnhancementProvider,
				'imageEnhancementModel' => $providerOptions['model'] ?? $enhancementService->getProviderModel($settings, $providerOptions),
			],
		);
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

		if (!$this->assetFileExists($asset)) {
			return $this->asJsonFailure('Could not find the source image file.');
		}

		try {
			$videoService->cleanupExpiredVideos();
		} catch (\Throwable $e) {
			Craft::warning('ImageEnhancer: Could not clean up expired videos: ' . $e->getMessage(), __METHOD__);
		}

		$userId = $this->getCurrentUserId();

		return $this->startOperation(
			$asset,
			'video generation',
			[
				'operation' => 'createVideo',
				'videoProvider' => $providerOptions['provider'],
				'videoModel' => $providerOptions['model'],
			],
			static fn(string $token) => new ArticleImageVideoJob([
				'assetId' => $asset->id,
				'userId' => $userId,
				'token' => $token,
				'videoPrompt' => $videoPrompt,
				'videoProvider' => $providerOptions['provider'],
				'videoModel' => $providerOptions['model'],
			]),
			[
				'videoProvider' => $providerOptions['provider'],
				'videoModel' => $providerOptions['model'],
			],
		);
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

		if (!$this->assetFileExists($asset)) {
			return $this->asJsonFailure('Could not find the original asset file.');
		}

		$userId = $this->getCurrentUserId();

		return $this->startOperation(
			$asset,
			'face blur',
			[
				'operation' => 'blurFaces',
				'blurMode' => $useManualFaces ? 'manual' : 'auto',
				'manualFaceCount' => $useManualFaces ? count($manualFaces) : 0,
			],
			static fn(string $token) => new ArticleImageFaceBlurJob([
				'assetId' => $asset->id,
				'userId' => $userId,
				'token' => $token,
				'useManualFaces' => $useManualFaces,
				'manualFaces' => $manualFaces ?: [],
			]),
		);
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
			// The CP renders the OpenAI list cache-only (curated fallback on a cold cache), so accept
			// both the discovered list and the curated list here.
			Settings::IMAGE_PROVIDER_OPENAI => array_merge(
				array_column(ImageEnhancer::getInstance()->getImageEnhancementModelOptions(), 'value'),
				array_column(Settings::imageEnhancementModelOptions(), 'value'),
			),
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
			'editorSourceUrl' => UrlHelper::actionUrl('craft-image-enhancer/article-image/editor-source', ['assetId' => $asset->id]),
			'editorVersion' => $asset->dateUpdated?->format('U.u'),
			'mimeType' => $asset->mimeType,
			'canReplace' => $this->canReplaceAssetFile($asset, Craft::$app->getUser()->getIdentity()),
		]);
	}

	/** Same-origin, permission-checked pixels, including assets on remote volumes. */
	public function actionEditorSource(): Response
	{
		$this->requireLogin();
		$asset = Craft::$app->getAssets()->getAssetById((int) Craft::$app->getRequest()->getQueryParam('assetId'));
		$user = Craft::$app->getUser()->getIdentity();
		if (!$user || !$this->isSupportedImageAsset($asset) || !$asset->canView($user) || !$asset->canSave($user)) {
			throw new NotFoundHttpException('Image not found.');
		}
		$response = Craft::$app->getResponse()->sendStreamAsFile($asset->getStream(), $asset->filename, [
			'mimeType' => $asset->mimeType,
			'inline' => true,
		]);
		$response->headers->set('Cache-Control', 'private, no-store, max-age=0');
		$response->headers->set('X-Content-Type-Options', 'nosniff');

		return $response;
	}

	/** Saves a flattened editor document through Craft's normal replacement service. */
	public function actionSaveEditor(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$asset = $this->getPostedAsset();
		$user = Craft::$app->getUser()->getIdentity();
		if (!$asset || !$user || !$asset->canSave($user) || !$this->canReplaceAssetFile($asset, $user)) {
			return $this->asJsonFailure('You do not have permission to replace this image.');
		}
		$request = Craft::$app->getRequest();
		$upload = UploadedFile::getInstanceByName('image');
		if (!$upload || $upload->error !== UPLOAD_ERR_OK) {
			return $this->asJsonFailure('The edited image could not be uploaded. Check the server upload limit.');
		}
		$info = ImageHelper::getImageInfo($upload->tempName);
		$error = EditorImageHelper::validate(
			$info, (int) filesize($upload->tempName), (int) $asset->width, (int) $asset->height, $asset->mimeType,
		);
		if ($error !== null) {
			return $this->asJsonFailure($error);
		}
		$mutex = Craft::$app->getMutex();
		$lock = 'image-enhancer:start-operation:' . $asset->id;
		if (!$mutex->acquire($lock)) {
			return $this->asJsonFailure('Another operation is changing this image. Try again in a moment.');
		}
		$tempPath = null;
		try {
			$asset = Craft::$app->getAssets()->getAssetById((int) $asset->id);
			if (!$asset || !$asset->canSave($user) || !$this->canReplaceAssetFile($asset, $user)) {
				return $this->asJsonFailure('Image is no longer available for replacement.');
			}
			$version = (string) $request->getBodyParam('version');
			if ($version === '' || $version !== $asset->dateUpdated?->format('U.u')) {
				return $this->asJsonFailure('This asset changed while you were editing. Download your edit, then reopen the editor.');
			}
			$active = Craft::$app->getCache()->get($this->getEnhancementAssetStatusCacheKey((int) $asset->id));
			if (self::isActiveStatus($active)) {
				return $this->asJsonFailure('Wait for the running image operation to finish before saving.');
			}
			$token = (string) $request->getBodyParam('token');
			$preview = null;
			if ($token !== '') {
				$status = $this->getOwnedStatus($token);
				$preview = $this->getPostedPreviewAsset();
				if (!$preview || !self::isPreviewBoundToStatus($status, (int) $asset->id, (int) $preview->id)) {
					return $this->asJsonFailure('The AI preview expired. Download your edit and reopen the editor.');
				}
			}
			$tempPath = EditorFileHelper::createTempPathForAsset($asset);
			if (!$upload->saveAs($tempPath)) {
				throw new \RuntimeException('Could not store the editor upload.');
			}
			// Decode and re-encode instead of trusting browser bytes or file metadata.
			ImageHelper::fitWithin($tempPath, $asset->mimeType, $info['width'], $info['height'], 95);
			ImageEnhancer::suppressAssetQueue(
				static fn() => Craft::$app->getAssets()->replaceAssetFile($asset, $tempPath, $asset->filename),
			);
			if ($preview !== null) {
				$this->deletePreviewIfPermitted($preview);
				$this->deleteEnhancementStatus($token);
			}

			return $this->asJson(['success' => true, 'assetId' => $asset->id, 'imageUrl' => $this->appendCacheBuster($asset->getUrl())]);
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Editor save failed (' . get_class($e) . ').', __METHOD__);
			return $this->asJsonFailure('Could not save the edited image. Your changes remain in the editor.');
		} finally {
			EditorFileHelper::delete($tempPath);
			$mutex->release($lock);
		}
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

		$asset = Craft::$app->getAssets()->getAssetById($assetId);
		if (!$this->isSupportedImageAsset($asset) || !$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to view this enhancement status.');
		}

		$status = $token !== ''
			? Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token))
			: Craft::$app->getCache()->get($this->getEnhancementAssetStatusCacheKey($assetId));

		// The per-asset key is shared between users; only restore the caller's own operation.
		if ($token === '' && !self::statusBelongsToUser($status, $this->getCurrentUserId())) {
			$status = null;
		}

		if (!is_array($status)) {
			return $this->asJson([
				'success' => true,
				'status' => $token !== '' ? 'pending' : 'idle',
				'assetId' => $assetId,
				'progress' => 0,
				'progressLabel' => $token !== '' ? 'Waiting for queue' : 'Idle',
			]);
		}

		if (!self::statusBelongsToUser($status, $this->getCurrentUserId())) {
			return $this->asJsonFailure('You do not have permission to view this enhancement status.');
		}

		if ((int) ($status['assetId'] ?? 0) !== $assetId) {
			return $this->asJsonFailure('Enhancement status token does not match this asset.');
		}

		$statusKey = (string) ($status['token'] ?? $token);
		$previewId = (int) ($status['previewId'] ?? 0);
		if (($status['status'] ?? null) === 'complete' && $previewId) {
			$previewAsset = Craft::$app->getAssets()->getAssetById($previewId);
			if (
				$previewAsset instanceof Asset &&
				self::isPreviewBoundToStatus($status, $assetId, (int) $previewAsset->id)
			) {
				// `token` is Craft's reserved token param on GET requests, so use `statusKey`.
				$status['enhancedUrl'] = UrlHelper::actionUrl('craft-image-enhancer/article-image/preview', [
					'assetId' => $assetId,
					'previewId' => $previewId,
					'statusKey' => $statusKey,
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
					'statusKey' => $statusKey,
				]);
			} else {
				$status['status'] = 'failed';
				$status['progressLabel'] = 'Video download expired';
				$status['message'] = 'The generated video is no longer available. Create it again.';
			}
		}

		unset($status['videoPath'], $status['userId']);

		return $this->asJson(array_merge(['success' => true], $status));
	}

	/**
	 * Streams an enhancement preview. Reads `statusKey` (not `token`, which Craft reserves).
	 *
	 * @throws NotFoundHttpException
	 * @throws \yii\base\InvalidConfigException
	 */
	public function actionPreview(): Response
	{
		$this->requireLogin();

		$request = Craft::$app->getRequest();
		$assetId = (int) $request->getQueryParam('assetId');
		$previewId = (int) $request->getQueryParam('previewId');
		$asset = Craft::$app->getAssets()->getAssetById($assetId);
		$previewAsset = Craft::$app->getAssets()->getAssetById($previewId);
		$status = $this->getOwnedStatus((string) $request->getQueryParam('statusKey'));
		$user = Craft::$app->getUser()->getIdentity();

		if (
			!$user ||
			!$this->isSupportedImageAsset($asset) ||
			!$this->isSupportedImageAsset($previewAsset) ||
			$status === null ||
			!self::isPreviewBoundToStatus($status, (int) $asset->id, (int) $previewAsset->id) ||
			!$this->canSaveAsset($asset) ||
			!$previewAsset->canView($user)
		) {
			throw new NotFoundHttpException('Enhanced preview not found.');
		}

		try {
			$stream = $previewAsset->getStream();
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Could not read enhanced preview: ' . $e->getMessage(), __METHOD__);
			throw new NotFoundHttpException('Enhanced preview not found.');
		}

		$response = Craft::$app->getResponse()->sendStreamAsFile($stream, $previewAsset->filename, [
			'mimeType' => $previewAsset->mimeType ?: 'application/octet-stream',
			'inline' => true,
		]);
		$response->headers->set('Cache-Control', 'private, no-store, max-age=0');

		return $response;
	}

	/**
	 * Downloads a generated video. Reads `statusKey` (not `token`, which Craft reserves).
	 *
	 * @throws NotFoundHttpException
	 */
	public function actionDownloadVideo(): Response
	{
		$this->requireLogin();

		$request = Craft::$app->getRequest();
		$assetId = (int) $request->getQueryParam('assetId');
		$asset = Craft::$app->getAssets()->getAssetById($assetId);
		$status = $this->getOwnedStatus((string) $request->getQueryParam('statusKey'));

		if (
			!$this->isSupportedImageAsset($asset) ||
			!$this->canSaveAsset($asset) ||
			$status === null ||
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

	/**
	 * Cancels an operation. Only the job recorded in the status is released; any posted
	 * `jobId` is ignored so a caller cannot release arbitrary queue jobs.
	 */
	public function actionCancel(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$asset = $this->getPostedAsset();
		$token = (string) Craft::$app->getRequest()->getBodyParam('token');
		$user = Craft::$app->getUser()->getIdentity();

		if (!$asset instanceof Asset || $token === '' || !$user) {
			return $this->asJsonFailure('Missing enhancement cancellation details.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to cancel this enhancement.');
		}

		$status = Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token));
		if (!is_array($status)) {
			return $this->asJsonFailure('This enhancement is no longer active.');
		}
		if (!self::canManageStatus($status, (int) $asset->id, (int) $user->id, (bool) $user->admin)) {
			return $this->asJsonFailure('You do not have permission to cancel this enhancement.');
		}

		if (isset($status['videoPath'])) {
			ImageEnhancer::getInstance()->aiVideoGeneration->deleteVideo(
				is_string($status['videoPath']) ? $status['videoPath'] : null,
			);
			unset($status['videoPath'], $status['videoFilename']);
		}
		$jobId = is_scalar($status['jobId'] ?? null) ? (string) $status['jobId'] : '';
		$this->setEnhancementStatus($token, array_merge($status, [
			'status' => 'canceled',
			'progress' => 1,
			'progressLabel' => 'Canceled',
		]));

		$previewId = (int) ($status['previewId'] ?? 0);
		if ($previewId && self::isPreviewBoundToStatus($status, (int) $asset->id, $previewId)) {
			$previewAsset = Craft::$app->getAssets()->getAssetById($previewId);
			if ($previewAsset instanceof Asset) {
				$this->deletePreviewIfPermitted($previewAsset);
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
		$user = Craft::$app->getUser()->getIdentity();

		if (!$asset instanceof Asset || !$user) {
			return $this->asJsonFailure('Missing enhancement asset.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to reset this enhancement status.');
		}

		$status = $token !== ''
			? Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token))
			: Craft::$app->getCache()->get($this->getEnhancementAssetStatusCacheKey((int) $asset->id));

		if (is_array($status)) {
			if ((int) ($status['assetId'] ?? 0) !== (int) $asset->id) {
				return $this->asJsonFailure('Enhancement status token does not match this asset.');
			}
			if (!self::canManageStatus($status, (int) $asset->id, (int) $user->id, (bool) $user->admin)) {
				// Another user's operation: leave it alone, and report idle for the caller.
				if ($token !== '') {
					return $this->asJsonFailure('You do not have permission to reset this enhancement status.');
				}

				return $this->asJson([
					'success' => true,
					'status' => 'idle',
					'assetId' => $asset->id,
				]);
			}
		}

		$this->deleteEnhancementStatus($token !== '' ? $token : ($status['token'] ?? null), (int) $asset->id);

		return $this->asJson([
			'success' => true,
			'status' => 'idle',
			'assetId' => $asset->id,
		]);
	}

	/**
	 * Replaces the original file with the preview. Mirrors core AssetsController::actionReplaceFile
	 * permissions (replaceFiles / replacePeerFiles), except for the caller's own repair upload.
	 */
	public function actionKeep(): Response
	{
		$this->requireLogin();
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$asset = $this->getPostedAsset();
		$previewAsset = $this->getPostedPreviewAsset();
		$token = (string) Craft::$app->getRequest()->getBodyParam('token');
		$status = $this->getOwnedStatus($token);
		$user = Craft::$app->getUser()->getIdentity();

		if (
			!$user ||
			!$asset instanceof Asset ||
			!$previewAsset instanceof Asset ||
			$status === null ||
			!self::isPreviewBoundToStatus($status, (int) $asset->id, (int) $previewAsset->id)
		) {
			return $this->asJsonFailure('Enhanced preview asset not found or invalid.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to keep this enhanced image.');
		}
		if (!$this->canReplaceAssetFile($asset, $user)) {
			return $this->asJsonFailure('You do not have permission to replace this file.');
		}

		$tempPath = null;
		try {
			$tempPath = $previewAsset->getCopyOfFile();

			ImageEnhancer::suppressAssetQueue(
				static fn() => Craft::$app->getAssets()->replaceAssetFile($asset, $tempPath, $asset->filename),
			);

			$updatedAsset = Craft::$app->getAssets()->getAssetById((int) $asset->id) ?: $asset;

			$this->deletePreviewIfPermitted($previewAsset);
			$this->deleteEnhancementStatus($token);

			return $this->asJson([
				'success' => true,
				'assetId' => $updatedAsset->id,
				'imageUrl' => $this->appendCacheBuster($updatedAsset->getUrl()),
			]);
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Keeping enhanced article image failed: ' . $e->getMessage(), __METHOD__);
			return $this->asJsonFailure('Could not keep the enhanced image.');
		} finally {
			if ($tempPath !== null && file_exists($tempPath)) {
				FileHelper::unlink($tempPath);
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
		$status = $this->getOwnedStatus($token);
		$user = Craft::$app->getUser()->getIdentity();

		if (
			!$user ||
			!$asset instanceof Asset ||
			!$previewAsset instanceof Asset ||
			$status === null ||
			!self::isPreviewBoundToStatus($status, (int) $asset->id, (int) $previewAsset->id)
		) {
			return $this->asJsonFailure('Enhanced preview asset not found or invalid.');
		}
		if (!$this->canSaveAsset($asset)) {
			return $this->asJsonFailure('You do not have permission to discard this enhanced image.');
		}
		if (!$previewAsset->canDelete($user)) {
			return $this->asJsonFailure('You do not have permission to delete the enhanced preview.');
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
			return $this->asJsonFailure('Could not discard the enhanced image.');
		}
	}

	/**
	 * Queues an AI operation for an asset under a short per-asset lock, refusing to start
	 * when the caller already has an active operation on it (repeated-click protection).
	 *
	 * @param string $label Human-readable operation label for messages.
	 * @param array $statusFields Operation-specific status fields; must include `operation`.
	 * @param callable(string): \craft\queue\BaseJob $jobFactory Builds the job for a status token.
	 * @param array $responseFields Extra fields for the success response.
	 */
	private function startOperation(Asset $asset, string $label, array $statusFields, callable $jobFactory, array $responseFields = []): Response
	{
		$userId = $this->getCurrentUserId();
		$mutex = Craft::$app->getMutex();
		$lockName = 'image-enhancer:start-operation:' . $asset->id;
		if (!$mutex->acquire($lockName)) {
			return $this->asJsonFailure('Another operation is starting for this image. Try again in a moment.');
		}

		$token = null;
		try {
			$existing = Craft::$app->getCache()->get($this->getEnhancementAssetStatusCacheKey((int) $asset->id));
			if (self::statusBelongsToUser($existing, $userId) && self::isActiveStatus($existing)) {
				return $this->asJsonFailure('An operation is already running for this image. Wait for it to finish or cancel it first.');
			}

			$token = bin2hex(random_bytes(16));
			$status = array_merge($statusFields, [
				'status' => 'queued',
				'assetId' => (int) $asset->id,
				'userId' => $userId,
				'progress' => 0,
				'progressLabel' => 'Queued',
			]);
			$this->setEnhancementStatus($token, $status);
			$jobId = Craft::$app->getQueue()->push($jobFactory($token));

			// The job may already have updated the status; merge instead of overwriting it.
			$current = Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token));
			$this->setEnhancementStatus($token, array_merge(is_array($current) ? $current : $status, [
				'jobId' => $jobId,
				'userId' => $userId,
			]));

			return $this->asJson(array_merge([
				'success' => true,
				'queued' => true,
				'assetId' => $asset->id,
				'operation' => $statusFields['operation'] ?? null,
				'jobId' => $jobId,
				'token' => $token,
				'statusUrl' => UrlHelper::actionUrl('craft-image-enhancer/article-image/status'),
			], $responseFields));
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Article image ' . $label . ' queueing failed: ' . $e->getMessage(), __METHOD__);
			if ($token !== null) {
				// Don't leave a phantom "queued" status that would block the next attempt.
				$this->deleteEnhancementStatus($token, (int) $asset->id);
			}

			return $this->asJsonFailure('Could not queue ' . $label . '.');
		} finally {
			$mutex->release($lockName);
		}
	}

	private function getPostedAsset(): ?Asset
	{
		$assetId = (int) Craft::$app->getRequest()->getBodyParam('assetId');
		if (!$assetId) {
			return null;
		}

		$asset = Craft::$app->getAssets()->getAssetById($assetId);
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

		$asset = Craft::$app->getAssets()->getAssetById($previewId);
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

		return $user && $this->hasRepairContextForAsset($asset, (int) $user->id);
	}

	/**
	 * Mirrors core AssetsController::actionReplaceFile: `replaceFiles:<volumeUid>`, plus
	 * `replacePeerFiles:<volumeUid>` when someone else uploaded the file. The user's own
	 * temporary upload (core idiom) and an authorized upload-repair session are exempt.
	 */
	private function canReplaceAssetFile(Asset $asset, User $user): bool
	{
		if ($this->hasRepairContextForAsset($asset, (int) $user->id)) {
			return true;
		}

		$volumeId = $asset->getVolumeId();
		if (!$volumeId) {
			$userTemporaryFolder = Craft::$app->getAssets()->getUserTemporaryUploadFolder($user);

			return (int) $userTemporaryFolder->id === (int) $asset->folderId;
		}

		$volumeUid = $asset->getVolume()->uid;
		if (!$user->can("replaceFiles:$volumeUid")) {
			return false;
		}

		return (int) $asset->uploaderId === (int) $user->id || $user->can("replacePeerFiles:$volumeUid");
	}

	private function hasRepairContextForAsset(Asset $asset, int $userId): bool
	{
		$repairToken = (string) Craft::$app->getRequest()->getParam('uploadRepairToken');

		return $userId > 0 &&
			$repairToken !== '' &&
			ImageEnhancer::getInstance()->assetRequirements->getAuthorizedRepairContext(
				$repairToken,
				$userId,
				(int) $asset->id,
			) !== null;
	}

	private function deleteElement(Asset $asset): void
	{
		ImageEnhancer::suppressAssetQueue(static fn() => Craft::$app->getElements()->deleteElement($asset));
	}

	/**
	 * Deletes a preview asset only when the current user may delete it; otherwise it is left
	 * for stale-preview cleanup.
	 */
	private function deletePreviewIfPermitted(Asset $previewAsset): void
	{
		$user = Craft::$app->getUser()->getIdentity();
		if (!$user || !$previewAsset->canDelete($user)) {
			Craft::warning('ImageEnhancer: Left enhancement preview asset ' . $previewAsset->id . ' in place; the user may not delete it.', __METHOD__);
			return;
		}

		$this->deleteElement($previewAsset);
	}

	/**
	 * Checks the file exists on its volume, for any filesystem type (honours Craft 5 volume subpaths).
	 */
	private function assetFileExists(Asset $asset): bool
	{
		try {
			return $asset->getVolume()->fileExists($asset->getPath());
		} catch (\Throwable $e) {
			Craft::warning('ImageEnhancer: Could not check asset file ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
			return false;
		}
	}

	private function appendCacheBuster(?string $url): ?string
	{
		if (!$url) {
			return null;
		}

		return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . time();
	}

	private function getCurrentUserId(): int
	{
		return (int) Craft::$app->getUser()->getId();
	}

	/**
	 * Returns the cached status for a token only when it belongs to the current user.
	 */
	private function getOwnedStatus(string $token): ?array
	{
		if ($token === '') {
			return null;
		}

		$status = Craft::$app->getCache()->get($this->getEnhancementStatusCacheKey($token));

		return self::statusBelongsToUser($status, $this->getCurrentUserId()) ? $status : null;
	}

	/**
	 * Whether a cached status was created by the given user. Statuses without a user are rejected.
	 */
	private static function statusBelongsToUser(mixed $status, int $userId): bool
	{
		return is_array($status) && $userId > 0 && (int) ($status['userId'] ?? 0) === $userId;
	}

	/**
	 * Whether a cached status still represents queued or running work.
	 */
	private static function isActiveStatus(mixed $status): bool
	{
		return is_array($status) && in_array($status['status'] ?? null, ['queued', 'running', 'pending'], true);
	}

	/**
	 * Whether a user may cancel/reset a status: it must belong to the asset, and to the user
	 * unless they are an admin.
	 */
	private static function canManageStatus(mixed $status, int $assetId, int $userId, bool $isAdmin): bool
	{
		if (!is_array($status) || $assetId <= 0 || (int) ($status['assetId'] ?? 0) !== $assetId) {
			return false;
		}

		return $isAdmin || self::statusBelongsToUser($status, $userId);
	}

	/**
	 * Whether a preview asset was produced by the operation recorded in a status. This is
	 * the only binding between an original and its preview (no filename heuristics).
	 */
	private static function isPreviewBoundToStatus(mixed $status, int $assetId, int $previewId): bool
	{
		return is_array($status) &&
			$assetId > 0 &&
			$previewId > 0 &&
			$previewId !== $assetId &&
			(int) ($status['assetId'] ?? 0) === $assetId &&
			(int) ($status['previewId'] ?? 0) === $previewId;
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
