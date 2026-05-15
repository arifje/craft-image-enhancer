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
	 
	public int $notificationThreshold = 50;
	
	// Enabled volume handles
	public array $allowedAssetFieldHandles = [];

	public function rules(): array
	{
		return [
			[['chatGptApiKey', 'slackWebhookUrl', 'slackChannel','chatGptResultLanguage','slackBotToken', 'chatGptModel'], 'string'],
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
	
}
