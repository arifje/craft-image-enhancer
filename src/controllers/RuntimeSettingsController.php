<?php

namespace arjanbrinkman\craftimagequalitychecker\controllers;

use arjanbrinkman\craftimagequalitychecker\ImageQualityChecker;
use Craft;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class RuntimeSettingsController extends Controller
{
	public function actionSave(): Response
	{
		$this->requirePostRequest();

		$user = Craft::$app->getUser()->getIdentity();
		if (!$user || !$user->admin) {
			throw new ForbiddenHttpException('Only admins can change Image Quality Checker runtime settings.');
		}

		$request = Craft::$app->getRequest();
		$enabled = (bool) $request->getBodyParam('enabled');
		$creativeEnhancementPromptOverride = (string) $request->getBodyParam('creativeEnhancementPromptOverride', '');
		$faceBlurDetectionPromptOverride = (string) $request->getBodyParam('faceBlurDetectionPromptOverride', '');

		try {
			ImageQualityChecker::getInstance()->runtimeSettings->setRuntimeSettings(
				$enabled,
				$creativeEnhancementPromptOverride,
				$faceBlurDetectionPromptOverride
			);
			Craft::$app->getSession()->setNotice('Image Quality Checker runtime settings saved.');
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Could not save runtime settings: ' . $e->getMessage(), __METHOD__);
			Craft::$app->getSession()->setError('Could not save Image Quality Checker runtime settings.');
		}

		return $this->redirectToPostedUrl();
	}
}
