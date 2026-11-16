<?php

namespace arjanbrinkman\craftimageenhancer\controllers;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/** Authenticated preview; temporary files are never served from a public volume. */
class CandidateController extends Controller
{
    public function actionPreview(string $operationId): Response
    {
        $this->requireLogin();
        $user = Craft::$app->getUser()->getIdentity();
        $path = ImageEnhancer::getInstance()->mcpEnhancements->previewPath($operationId, $user);
        $response = Craft::$app->getResponse();
        $response->getHeaders()->set('Cache-Control', 'private, no-store');
        $response->getHeaders()->set('Pragma', 'no-cache');
        $response->getHeaders()->set('X-Content-Type-Options', 'nosniff');
        return $response->sendFile($path, basename($path), ['inline' => true]);
    }
}
