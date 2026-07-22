<?php

namespace arjanbrinkman\craftimageenhancer\controllers;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\services\AssetRequirementService;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementCondition;
use craft\errors\AssetDisallowedExtensionException;
use craft\errors\UploadFailedException;
use craft\fields\Assets as AssetsField;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\models\VolumeFolder;
use craft\web\Controller;
use craft\web\UploadedFile;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class UploadAssistantController extends Controller
{
    public function actionUpload(): Response
    {
        $this->requireCpRequest();
        $this->requireLogin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $uploadedFile = UploadedFile::getInstanceByName('assets-upload');
        if (!$uploadedFile) {
            throw new BadRequestHttpException('No file was uploaded.');
        }

        $fieldId = (int) Craft::$app->getRequest()->getBodyParam('fieldId');
        $field = Craft::$app->getFields()->getFieldById($fieldId);
        if (!$field instanceof AssetsField || !$this->isAssistantEnabledForField($field)) {
            throw new BadRequestHttpException('This asset field is not configured for upload assistance.');
        }

        $referenceElement = $this->getReferenceElement();
        $folderId = $field->resolveDynamicPathToFolderId($referenceElement);
        $targetFolder = $folderId ? Craft::$app->getAssets()->findFolder(['id' => $folderId]) : null;
        if (!$targetFolder instanceof VolumeFolder) {
            throw new BadRequestHttpException('The target upload folder is not valid.');
        }
        $this->requireSavePermission($targetFolder);

        $tempPath = $this->getUploadedFileTempPath($uploadedFile);
        $selectionCondition = $field->getSelectionCondition();
        $originalFilename = AssetsHelper::prepareAssetName($uploadedFile->name);
        if ($selectionCondition instanceof ElementCondition) {
            $selectionCondition->referenceElement = $referenceElement;
            $saveFolder = Craft::$app->getAssets()->getUserTemporaryUploadFolder();
            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $saveFilename = uniqid('asset', true) . ($extension ? '.' . $extension : '');
        } else {
            $saveFolder = $targetFolder;
            $saveFilename = $originalFilename;
        }

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename($saveFilename);
        $asset->newFolderId = $saveFolder->id;
        $asset->setVolumeId($saveFolder->volumeId);
        $asset->uploaderId = Craft::$app->getUser()->getId();
        $asset->avoidFilenameConflicts = true;
        $asset->title = AssetsHelper::filename2Title(pathinfo($originalFilename, PATHINFO_FILENAME));
        $asset->setScenario(Asset::SCENARIO_CREATE);

        ImageEnhancer::$skipAssetQueue = true;
        try {
            $saved = Craft::$app->getElements()->saveElement($asset);
        } finally {
            ImageEnhancer::$skipAssetQueue = false;
        }

        if (!$saved) {
            return $this->asModelFailure($asset);
        }

        if (!$selectionCondition instanceof ElementCondition || $selectionCondition->matchElement($asset)) {
            try {
                if ($saveFolder->id !== $targetFolder->id) {
                    $this->moveAssetToTarget($asset, $targetFolder, $originalFilename);
                }
            } catch (\Throwable $e) {
                $this->deleteAsset($asset);
                Craft::error('ImageEnhancer: Could not finalize uploaded image: ' . $e->getMessage(), __METHOD__);
                throw new BadRequestHttpException('Could not save the uploaded image in its target folder.');
            }
            $this->queueAssetAnalysisSafely($asset, $this->getReferenceEntryId($referenceElement));

            return $this->uploadSuccessResponse($asset);
        }

        $inspection = $this->requirements()->inspect($field, $asset, $referenceElement);
        if ($asset->kind !== 'image' || !$inspection['repairable']) {
            $this->deleteAsset($asset);
            throw new BadRequestHttpException(sprintf('%s is not selectable for this field and cannot be repaired automatically.', $uploadedFile->name));
        }

        $userId = (int) Craft::$app->getUser()->getId();
        try {
            $token = $this->requirements()->createRepairContext(
                $asset,
                $field,
                $targetFolder->id,
                $originalFilename,
                $referenceElement?->getId(),
                $referenceElement ? (int) $referenceElement->getSite()->id : null,
                $userId,
            );
        } catch (\Throwable $e) {
            $this->deleteAsset($asset);
            Craft::error('ImageEnhancer: Could not create upload repair session: ' . $e->getMessage(), __METHOD__);
            throw new BadRequestHttpException('Could not start an upload repair session.');
        }

        return $this->asJson(array_merge([
            'success' => true,
            'needsRepair' => true,
            'assetId' => $asset->id,
            'filename' => $uploadedFile->name,
            'repairToken' => $token,
            'previewUrl' => UrlHelper::actionUrl('craft-image-enhancer/upload-assistant/preview', ['token' => $token]),
        ], $inspection));
    }

    public function actionLocalRepair(): Response
    {
        $this->requireCpRequest();
        $this->requireLogin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $token = (string) Craft::$app->getRequest()->getBodyParam('repairToken');
        $repair = $this->getRepair($token);
        if ($repair === null) {
            return $this->jsonFailure('This upload repair session has expired.');
        }

        $inspection = $this->requirements()->inspect($repair['field'], $repair['asset'], $repair['referenceElement']);
        $targetWidth = (int) ($inspection['targetWidth'] ?? 0);
        $targetHeight = (int) ($inspection['targetHeight'] ?? 0);
        if (!$inspection['repairable'] || !$targetWidth || !$targetHeight) {
            return $this->jsonFailure('These field requirements cannot be resolved with a proportional local resize.');
        }

        $sourcePath = null;
        $targetPath = null;
        try {
            $sourcePath = $repair['asset']->getCopyOfFile();
            $targetPath = $this->tempImagePath($repair['asset']);
            $image = Craft::$app->getImages()->loadImage($sourcePath);
            $image->resize($targetWidth, $targetHeight);
            if (!$image->saveAs($targetPath)) {
                return $this->jsonFailure('Craft could not save the resized image.');
            }

            ImageEnhancer::$skipAssetQueue = true;
            try {
                Craft::$app->getAssets()->replaceAssetFile($repair['asset'], $targetPath, $repair['asset']->filename);
            } finally {
                ImageEnhancer::$skipAssetQueue = false;
            }
        } catch (\Throwable $e) {
            Craft::error('ImageEnhancer: Local upload repair failed: ' . $e->getMessage(), __METHOD__);
            return $this->jsonFailure('Could not resize the image.');
        } finally {
            if ($sourcePath && file_exists($sourcePath)) {
                FileHelper::unlink($sourcePath);
            }
            if ($targetPath && file_exists($targetPath)) {
                FileHelper::unlink($targetPath);
            }
        }

        return $this->finalizeRepair($token);
    }

    public function actionFinalize(): Response
    {
        $this->requireCpRequest();
        $this->requireLogin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return $this->finalizeRepair((string) Craft::$app->getRequest()->getBodyParam('repairToken'));
    }

    public function actionDiscard(): Response
    {
        $this->requireCpRequest();
        $this->requireLogin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $token = (string) Craft::$app->getRequest()->getBodyParam('repairToken');
        $repair = $this->getRepair($token);
        if ($repair === null) {
            return $this->asJson(['success' => true, 'discarded' => true]);
        }

        $this->deleteAsset($repair['asset']);
        $this->requirements()->deleteRepairContext($token);

        return $this->asJson(['success' => true, 'discarded' => true]);
    }

    public function actionPreview(string $token): Response
    {
        $this->requireCpRequest();
        $this->requireLogin();

        $repair = $this->getRepair($token);
        if ($repair === null) {
            throw new BadRequestHttpException('This upload repair session has expired.');
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', $repair['asset']->mimeType ?: 'application/octet-stream');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->content = $repair['asset']->getContents();

        return $response;
    }

    private function finalizeRepair(string $token): Response
    {
        $repair = $this->getRepair($token);
        if ($repair === null) {
            return $this->jsonFailure('This upload repair session has expired.');
        }

        $asset = Craft::$app->getAssets()->getAssetById((int) $repair['asset']->id);
        if (!$asset instanceof Asset) {
            return $this->jsonFailure('The uploaded asset is no longer available.');
        }

        $inspection = $this->requirements()->inspect($repair['field'], $asset, $repair['referenceElement']);
        if (!$inspection['selectable']) {
            return $this->asJson(array_merge([
                'success' => true,
                'complete' => false,
                'message' => 'The image still does not meet every field requirement.',
            ], $inspection));
        }

        $targetFolder = Craft::$app->getAssets()->findFolder(['id' => (int) $repair['context']['targetFolderId']]);
        if (!$targetFolder instanceof VolumeFolder) {
            return $this->jsonFailure('The target upload folder is no longer available.');
        }
        $this->requireSavePermission($targetFolder);

        try {
            $this->moveAssetToTarget($asset, $targetFolder, (string) $repair['context']['originalFilename']);
        } catch (\Throwable $e) {
            Craft::error('ImageEnhancer: Could not finalize repaired upload: ' . $e->getMessage(), __METHOD__);
            return $this->jsonFailure('Could not save the repaired upload.');
        }

        $this->requirements()->deleteRepairContext($token);
        $this->queueAssetAnalysisSafely($asset, $this->getReferenceEntryId($repair['referenceElement']));

        return $this->asJson([
            'success' => true,
            'complete' => true,
            'assetId' => $asset->id,
            'filename' => $asset->filename,
            'url' => $asset->getUrl(),
            'width' => $asset->width,
            'height' => $asset->height,
        ]);
    }

    private function getRepair(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $context = $this->requirements()->getAuthorizedRepairContext(
            $token,
            (int) Craft::$app->getUser()->getId(),
        );
        if (!$context) {
            return null;
        }

        $asset = Craft::$app->getAssets()->getAssetById((int) ($context['assetId'] ?? 0));
        $field = Craft::$app->getFields()->getFieldById((int) ($context['fieldId'] ?? 0));
        if (!$asset instanceof Asset || !$field instanceof AssetsField || !$this->isAssistantEnabledForField($field)) {
            return null;
        }

        $referenceElement = null;
        if (!empty($context['referenceElementId'])) {
            $referenceElement = Craft::$app->getElements()->getElementById(
                (int) $context['referenceElementId'],
                null,
                $context['siteId'] ?? null,
            );
        }

        return [
            'context' => $context,
            'asset' => $asset,
            'field' => $field,
            'referenceElement' => $referenceElement,
        ];
    }

    private function getReferenceElement(): ?ElementInterface
    {
        $elementId = (int) Craft::$app->getRequest()->getBodyParam('elementId');
        if (!$elementId) {
            return null;
        }

        $siteId = (int) Craft::$app->getRequest()->getBodyParam('siteId') ?: null;

        return Craft::$app->getElements()->getElementById($elementId, null, $siteId);
    }

    private function getReferenceEntryId(?ElementInterface $element): ?int
    {
        if (!$element) {
            return null;
        }

        $ownerId = null;
        if (method_exists($element, 'getOwner')) {
            $owner = $element->getOwner();
            $ownerId = $owner instanceof ElementInterface ? $owner->getId() : null;
        }

        return $ownerId ? (int) $ownerId : $element->getId();
    }

    private function moveAssetToTarget(Asset $asset, VolumeFolder $folder, string $filename): void
    {
        $asset->newFilename = AssetsHelper::prepareAssetName($filename);
        $asset->newFolderId = $folder->id;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_MOVE);

        ImageEnhancer::$skipAssetQueue = true;
        try {
            if (!Craft::$app->getElements()->saveElement($asset)) {
                throw new \RuntimeException(implode(' ', $asset->getErrorSummary(true)));
            }
        } finally {
            ImageEnhancer::$skipAssetQueue = false;
        }
    }

    private function uploadSuccessResponse(Asset $asset): Response
    {
        return $this->asJson([
            'success' => true,
            'filename' => $asset->filename,
            'assetId' => $asset->id,
            'url' => $asset->getUrl(),
        ]);
    }

    private function requireSavePermission(VolumeFolder $folder): void
    {
        $volumeUid = $folder->getVolume()->uid;
        if (!Craft::$app->getUser()->checkPermission("saveAssets:$volumeUid")) {
            throw new ForbiddenHttpException('You do not have permission to upload assets to this volume.');
        }
    }

    private function isAssistantEnabledForField(AssetsField $field): bool
    {
        $settings = ImageEnhancer::getInstance()->getSettings();
        if (!$settings->enableUploadRequirementAssistant) {
            return false;
        }

        return empty($settings->cpEnhancerAssetFieldHandles) || in_array($field->handle, $settings->cpEnhancerAssetFieldHandles, true);
    }

    private function tempImagePath(Asset $asset): string
    {
        $extension = pathinfo($asset->filename, PATHINFO_EXTENSION);
        $tempPath = tempnam(Craft::$app->getPath()->getTempPath(), 'image-enhancer-upload-');
        if ($tempPath === false) {
            throw new \RuntimeException('Could not create a temporary image file.');
        }
        if (!$extension) {
            return $tempPath;
        }

        FileHelper::unlink($tempPath);

        return $tempPath . '.' . $extension;
    }

    private function getUploadedFileTempPath(UploadedFile $uploadedFile): string
    {
        if ($uploadedFile->getHasError()) {
            throw new UploadFailedException($uploadedFile->error);
        }

        $allowedExtensions = Craft::$app->getConfig()->getGeneral()->allowedFileExtensions;
        $extension = strtolower(pathinfo($uploadedFile->name, PATHINFO_EXTENSION));
        if (is_array($allowedExtensions) && !in_array($extension, $allowedExtensions, true)) {
            throw new AssetDisallowedExtensionException(Craft::t('app', '"{extension}" is not an allowed file extension.', [
                'extension' => $extension,
            ]));
        }

        $tempPath = $uploadedFile->saveAsTempFile();
        if ($tempPath === false) {
            throw new UploadFailedException(UPLOAD_ERR_CANT_WRITE);
        }

        return $tempPath;
    }

    private function deleteAsset(Asset $asset): void
    {
        ImageEnhancer::$skipAssetQueue = true;
        try {
            Craft::$app->getElements()->deleteElement($asset, true);
        } finally {
            ImageEnhancer::$skipAssetQueue = false;
        }
    }

    private function requirements(): AssetRequirementService
    {
        return ImageEnhancer::getInstance()->assetRequirements;
    }

    private function queueAssetAnalysisSafely(Asset $asset, ?int $entryId): void
    {
        try {
            ImageEnhancer::getInstance()->queueAssetAnalysis($asset, $entryId);
        } catch (\Throwable $e) {
            Craft::warning('ImageEnhancer: Could not queue analysis for uploaded asset: ' . $e->getMessage(), __METHOD__);
        }
    }

    private function jsonFailure(string $message): Response
    {
        return $this->asJson([
            'success' => false,
            'message' => $message,
        ]);
    }
}
