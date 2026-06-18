<?php

namespace arjanbrinkman\craftimagequalitychecker\utilities;

use arjanbrinkman\craftimagequalitychecker\ImageQualityChecker;
use Craft;
use craft\base\Utility;

class QualityCheckUtility extends Utility
{
	public static function displayName(): string
	{
		return Craft::t('_image-quality-checker', 'Image Quality Checker');
	}

	public static function id(): string
	{
		return 'image-quality-checker';
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
		return Craft::$app->getView()->renderTemplate('_image-quality-checker/_utility.twig', [
			'enabled' => ImageQualityChecker::getInstance()->runtimeSettings->isQualityCheckEnabled(),
		]);
	}
}
