<?php

namespace arjanbrinkman\craftimageenhancer\controllers;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class ImageCreatorController extends Controller
{
    public function actionTemplateData(): Response
    {
        $this->requireCreatorRequest();
        $templateId = trim((string) Craft::$app->getRequest()->getBodyParam('template'));

        return $this->runJsonAction(
            fn(): array => ImageEnhancer::getInstance()->imageCreator->getTemplateData($templateId),
            'fetch template data',
        );
    }

    public function actionUpload(): Response
    {
        $this->requireCreatorRequest();
        $uploadedFile = UploadedFile::getInstanceByName('file');
        if (!$uploadedFile) {
            throw new BadRequestHttpException('No source image was uploaded.');
        }

        return $this->runJsonAction(
            fn(): array => ImageEnhancer::getInstance()->imageCreator->uploadSource(
                $uploadedFile,
                (int) Craft::$app->getUser()->getId(),
            ),
            'upload a source image',
        );
    }

    public function actionGenerate(): Response
    {
        $this->requireCreatorRequest();

        return $this->runJsonAction(
            fn(): array => ImageEnhancer::getInstance()->imageCreator->generate(
                Craft::$app->getRequest()->getBodyParams(),
                (int) Craft::$app->getUser()->getId(),
            ),
            'generate an image',
        );
    }

    public function actionSave(): Response
    {
        $this->requireCreatorRequest();
        $request = Craft::$app->getRequest();
        $token = trim((string) $request->getBodyParam('generationToken'));
        if ($token === '') {
            throw new BadRequestHttpException('The generated image token is missing.');
        }

        return $this->runJsonAction(function() use ($token, $request): array {
            $asset = ImageEnhancer::getInstance()->imageCreator->saveGeneratedAsset(
                $token,
                [
                    'fieldId' => (int) $request->getBodyParam('fieldId'),
                    'elementId' => (int) $request->getBodyParam('elementId'),
                    'siteId' => (int) $request->getBodyParam('siteId'),
                    'volumeId' => (int) $request->getBodyParam('volumeId'),
                ],
                (int) Craft::$app->getUser()->getId(),
            );

            return [
                'assetId' => (int) $asset->id,
                'title' => (string) $asset->title,
                'filename' => (string) $asset->filename,
                'url' => (string) $asset->getUrl(),
                'cpEditUrl' => (string) $asset->getCpEditUrl(),
            ];
        }, 'save a generated image');
    }

    private function requireCreatorRequest(): void
    {
        $this->requireCpRequest();
        $this->requireLogin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
    }

    private function runJsonAction(callable $callback, string $operation): Response
    {
        try {
            return $this->asJson(array_merge([
                'success' => true,
            ], $callback()));
        } catch (\Throwable $e) {
            Craft::error(
                sprintf('ImageEnhancer: Could not %s: %s', $operation, $e->getMessage()),
                __METHOD__,
            );

            $response = $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
            $response->setStatusCode(400);

            return $response;
        }
    }
}
