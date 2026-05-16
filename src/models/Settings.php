<?php

namespace arjanbrinkman\craftimagequalitychecker\models;

use Craft;
use craft\base\Model;

/**
 * Image Quality Checker settings
 */
class Settings extends Model
{
	public const MODEL_LATEST = 'latest';
	public const ENHANCEMENT_DISABLED = 'disabled';
	public const ENHANCEMENT_SAFE = 'safe';
	public const ENHANCEMENT_CREATIVE = 'creative';
	public const ENHANCEMENT_TRIGGER_THRESHOLD = 'threshold';
	public const ENHANCEMENT_TRIGGER_ALWAYS = 'always';
	public const ENHANCEMENT_ACTION_REPLACE = 'replace';
	public const ENHANCEMENT_ACTION_ADD = 'add';

	// ChatGPT
	public string $chatGptApiKey = '';
	public string $chatGptPrompt = 'You are an expert in image quality. Evaluate this image from 1 (very bad) to 100 (excellent), considering sharpness, blur, noise, and motion blur.';
	public string $chatGptResultLanguage = 'Dutch';
	public string $chatGptModel = self::MODEL_LATEST;
	
	// Slack notification
	public bool $slackNotification = true;
	public string $slackWebhookUrl = '';
	public string $slackBotToken = ''; // Required for postMessage method
 	public string $slackChannel = '';
	
	// Email notification
	public bool $emailNotification = false;
	public string $emailNotificationRecipient = '';

	// Debugging
	public bool $debugLogging = false;
	 
	public int $notificationThreshold = 50;
	
	// Enabled volume handles
	public array $allowedAssetFieldHandles = [];

	// Image enhancement
	public string $imageEnhancementMode = self::ENHANCEMENT_DISABLED;
	public string $imageEnhancementTrigger = self::ENHANCEMENT_TRIGGER_THRESHOLD;
	public string $imageEnhancementAction = self::ENHANCEMENT_ACTION_REPLACE;
	public int $safeEnhancementMaxWidth = 2400;
	public int $safeEnhancementJpegQuality = 90;
	public string $creativeEnhancementPrompt = 'Enhance this exact image as a conservative photo restoration/edit. Preserve the same subject, crop, composition, perspective, background, scene, people, objects, clothing, text, and all visual context. Do not add, remove, replace, extend, uncrop, zoom, reframe, or invent anything. Only improve technical quality such as sharpness, clarity, mild noise reduction, compression artifacts, and natural color/contrast.';

	public function rules(): array
	{
		return [
			[['chatGptApiKey', 'slackWebhookUrl', 'slackChannel','chatGptResultLanguage','slackBotToken', 'chatGptModel', 'imageEnhancementMode', 'imageEnhancementTrigger', 'imageEnhancementAction', 'creativeEnhancementPrompt'], 'string'],
			[['debugLogging'], 'boolean'],
			[['safeEnhancementMaxWidth', 'safeEnhancementJpegQuality'], 'integer'],
			[['allowedAssetFieldHandles'], 'safe'],
		];
	}

	public static function fallbackChatGptModels(): array
	{
		return [
			self::MODEL_LATEST,
			'gpt-5.5',
			'gpt-5.4',
			'gpt-5.2',
			'gpt-5.1',
			'gpt-5',
			'gpt-4.1',
			'gpt-4o',
			'gpt-4o-mini',
			'gpt-4-turbo',
		];
	}

	public static function isSupportedChatGptModel(string $model): bool
	{
		if (!str_starts_with($model, 'gpt-')) {
			return false;
		}

		foreach (['audio', 'realtime', 'search', 'transcribe', 'tts', 'codex', 'image'] as $unsupported) {
			if (str_contains($model, $unsupported)) {
				return false;
			}
		}

		return true;
	}

	public static function imageEnhancementModeOptions(): array
	{
		return [
			['label' => 'Disabled', 'value' => self::ENHANCEMENT_DISABLED],
			['label' => 'Imagick safe optimization', 'value' => self::ENHANCEMENT_SAFE],
			['label' => 'OpenAI / ChatGPT AI enhancement', 'value' => self::ENHANCEMENT_CREATIVE],
		];
	}

	public static function imageEnhancementTriggerOptions(): array
	{
		return [
			['label' => 'Only when score is below threshold', 'value' => self::ENHANCEMENT_TRIGGER_THRESHOLD],
			['label' => 'Always enhance and skip quality check', 'value' => self::ENHANCEMENT_TRIGGER_ALWAYS],
		];
	}

	public static function imageEnhancementActionOptions(): array
	{
		return [
			['label' => 'Replace original image', 'value' => self::ENHANCEMENT_ACTION_REPLACE],
			['label' => 'Add enhanced image next to original', 'value' => self::ENHANCEMENT_ACTION_ADD],
		];
	}
	
}
