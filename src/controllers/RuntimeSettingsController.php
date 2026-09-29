<?php

namespace arjanbrinkman\craftimageenhancer\controllers;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class RuntimeSettingsController extends Controller
{
	/**
	 * Runtime settings are DB-stored operational toggles (not project config), so admins may
	 * change them even when allowAdminChanges is off: `requireAdmin(false)`.
	 *
	 * @throws \yii\web\BadRequestHttpException
	 * @throws \yii\web\ForbiddenHttpException
	 */
	public function actionSave(): Response
	{
		$this->requireCpRequest();
		$this->requireAdmin(false);
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		$enabled = (bool) $request->getBodyParam('enabled');
		$creativeEnhancementPromptOverride = (string) $request->getBodyParam('creativeEnhancementPromptOverride', '');
		$faceBlurDetectionPromptOverride = (string) $request->getBodyParam('faceBlurDetectionPromptOverride', '');

		try {
			ImageEnhancer::getInstance()->runtimeSettings->setRuntimeSettings(
				$enabled,
				$creativeEnhancementPromptOverride,
				$faceBlurDetectionPromptOverride
			);
			Craft::$app->getSession()->setNotice('Image Enhancer runtime settings saved.');
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Could not save runtime settings: ' . $e->getMessage(), __METHOD__);
			Craft::$app->getSession()->setError('Could not save Image Enhancer runtime settings.');
		}

		return $this->redirectToPostedUrl();
	}
}
