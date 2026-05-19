<?php

namespace arjanbrinkman\craftimagequalitychecker\controllers;

use arjanbrinkman\craftimagequalitychecker\ImageQualityChecker;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class RuntimeSettingsController extends Controller
{
	public function actionSave(): Response
	{
		$this->requireAdmin();
		$this->requirePostRequest();

		$enabled = (bool) Craft::$app->getRequest()->getBodyParam('enabled');

		try {
			ImageQualityChecker::getInstance()->runtimeSettings->setQualityCheckEnabled($enabled);
			Craft::$app->getSession()->setNotice('Image Quality Checker runtime settings saved.');
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Could not save runtime settings: ' . $e->getMessage(), __METHOD__);
			Craft::$app->getSession()->setError('Could not save Image Quality Checker runtime settings.');
		}

		return $this->redirectToPostedUrl();
	}
}
