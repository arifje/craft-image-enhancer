<?php

namespace arjanbrinkman\craftimageenhancer\utilities;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use Craft;
use craft\base\Utility;

class QualityCheckUtility extends Utility
{
	public static function displayName(): string
	{
		return Craft::t('craft-image-enhancer', 'Image Enhancer');
	}

	public static function id(): string
	{
		return 'image-enhancer';
	}

	public static function icon(): ?string
	{
		return dirname(__DIR__) . '/icon.svg';
	}

	public static function iconPath(): ?string
	{
		return self::icon();
	}

	public static function contentHtml(): string
	{
		$plugin = ImageEnhancer::getInstance();
		$runtimeSettings = $plugin->runtimeSettings;
		$settings = $plugin->getSettings();

		return Craft::$app->getView()->renderTemplate('craft-image-enhancer/_utility.twig', [
			'enabled' => $runtimeSettings->isQualityCheckEnabled(),
			'creativeEnhancementPromptOverride' => $runtimeSettings->getCreativeEnhancementPromptOverride(),
			'faceBlurDetectionPromptOverride' => $runtimeSettings->getFaceBlurDetectionPromptOverride(),
			'creativeEnhancementPromptDefault' => $settings->getEffectiveCreativeEnhancementPrompt(),
			'faceBlurDetectionPromptDefault' => $settings->getEffectiveFaceBlurDetectionPrompt(),
		]);
	}
}
