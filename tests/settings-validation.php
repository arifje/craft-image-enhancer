<?php

declare(strict_types=1);

// Settings validation regression checks. Uses the real Yii/Craft model and validator
// classes from vendor/ without bootstrapping a Craft application.
// Run: php tests/settings-validation.php

use arjanbrinkman\craftimageenhancer\models\Settings;
use craft\base\Model;
use craft\events\DefineRulesEvent;
use yii\base\Event;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/../vendor/craftcms/cms/src/Craft.php';

$checks = 0;
function check(bool $condition, string $message): void
{
	global $checks;
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$checks++;
}

/**
 * Applies submitted values the way Plugins::savePluginSettings() does, then validates.
 *
 * @return array<string, string[]>
 */
function errorsFor(array $values, ?Settings $settings = null): array
{
	$settings ??= new Settings();
	$settings->setAttributes($values, false);
	$settings->validate();

	return $settings->getErrors();
}

// Defaults are valid.
check(errorsFor([]) === [], 'Default settings must validate.');

// defineRules() keeps Craft's EVENT_DEFINE_RULES extension point.
$handler = static function(DefineRulesEvent $event): void {
	$event->rules[] = ['slackChannel', 'required'];
};
Event::on(Settings::class, Model::EVENT_DEFINE_RULES, $handler);
check(isset(errorsFor(['slackChannel' => ''])['slackChannel']), 'EVENT_DEFINE_RULES rules must apply.');
Event::off(Settings::class, Model::EVENT_DEFINE_RULES, $handler);

// Cleared or non-numeric integer fields must not silently become 0.
foreach (['notificationThreshold', 'failedEnhancementRetryDelay', 'safeEnhancementMaxWidth', 'safeEnhancementJpegQuality', 'creativeEnhancementClarityLevel'] as $attribute) {
	check(isset(errorsFor([$attribute => ''])[$attribute]), "Blank $attribute must be rejected.");
	check(isset(errorsFor([$attribute => '   '])[$attribute]), "Whitespace $attribute must be rejected.");
	check(isset(errorsFor([$attribute => 'abc'])[$attribute]), "Non-numeric $attribute must be rejected.");
	check(isset(errorsFor([$attribute => '5.5'])[$attribute]), "Decimal $attribute must be rejected.");
}
check(str_contains(errorsFor(['failedEnhancementRetryDelay' => ''])['failedEnhancementRetryDelay'][0], 'cannot be blank'), 'Blank input needs a blank message.');
check(errorsFor(['failedEnhancementRetryDelay' => '0', 'notificationThreshold' => '0']) === [], 'Explicit 0 is valid where 0 is in range.');
check(errorsFor(['safeEnhancementMaxWidth' => ' 1200 ', 'safeEnhancementJpegQuality' => 85]) === [], 'Numeric strings and ints are valid.');

// A later valid submission clears an earlier invalid one on the same model.
$settings = new Settings();
errorsFor(['safeEnhancementMaxWidth' => ''], $settings);
check(errorsFor(['safeEnhancementMaxWidth' => '1600'], $settings) === [], 'Valid resubmission must clear blank state.');
check($settings->safeEnhancementMaxWidth === 1600, 'Integer input must be typecast.');

// Numeric ranges.
$ranges = [
	'notificationThreshold' => [0, 100],
	'safeEnhancementMaxWidth' => [1, 10000],
	'safeEnhancementJpegQuality' => [1, 100],
	'failedEnhancementRetryDelay' => [0, 86400],
	'creativeEnhancementNoiseReductionLevel' => [Settings::ENHANCEMENT_LEVEL_MIN, Settings::ENHANCEMENT_LEVEL_MAX],
];
foreach ($ranges as $attribute => [$min, $max]) {
	check(errorsFor([$attribute => $min]) === [], "$attribute minimum must be valid.");
	check(errorsFor([$attribute => $max]) === [], "$attribute maximum must be valid.");
	check(isset(errorsFor([$attribute => $min - 1])[$attribute]), "$attribute below minimum must be rejected.");
	check(isset(errorsFor([$attribute => $max + 1])[$attribute]), "$attribute above maximum must be rejected.");
}

// Enum-like settings accept exactly their option values.
$enums = [
	'chatGptResultLanguage' => Settings::chatGptResultLanguageOptions(),
	'imageEnhancementMode' => Settings::imageEnhancementModeOptions(),
	'imageEnhancementTrigger' => Settings::imageEnhancementTriggerOptions(),
	'imageEnhancementAction' => Settings::imageEnhancementActionOptions(),
	'imageEnhancementProvider' => Settings::imageEnhancementProviderOptions(),
	'imageEnhancementFaceHandling' => Settings::imageEnhancementFaceHandlingOptions(),
	'xAiImageEnhancementModel' => Settings::xAiImageEnhancementModelOptions(),
	'googleImageEnhancementModel' => Settings::googleImageEnhancementModelOptions(),
];
foreach ($enums as $attribute => $options) {
	foreach (array_column($options, 'value') as $value) {
		check(errorsFor([$attribute => $value]) === [], "$attribute must accept '$value'.");
	}
	check(isset(errorsFor([$attribute => 'bogus'])[$attribute]), "$attribute must reject unknown values.");
	check(isset(errorsFor([$attribute => ''])[$attribute]), "$attribute must not be blank.");
}
check(errorsFor(['imageEnhancementProvider' => Settings::IMAGE_PROVIDER_FRONTEND]) === [], 'Frontend provider choice is valid.');

// Model IDs.
foreach ([Settings::MODEL_LATEST, 'gpt-5.5', 'gpt-4o-mini'] as $model) {
	check(errorsFor(['chatGptModel' => $model]) === [], "chatGptModel must accept $model.");
}
foreach (['gpt-image-1', 'dall-e-3', 'gpt-4o-realtime', ''] as $model) {
	check(isset(errorsFor(['chatGptModel' => $model])['chatGptModel']), "chatGptModel must reject '$model'.");
}
foreach (['gpt-image-2', 'gpt-image-2.5-sunburst-2026-09-08', 'chatgpt-image-latest'] as $model) {
	check(errorsFor(['imageEnhancementModel' => $model]) === [], "imageEnhancementModel must accept $model.");
}
foreach (['gpt-5.5', 'gpt-image-bad<script>', ''] as $model) {
	check(isset(errorsFor(['imageEnhancementModel' => $model])['imageEnhancementModel']), "imageEnhancementModel must reject '$model'.");
}

// Email recipient.
check(errorsFor(['emailNotificationRecipient' => '']) === [], 'Empty email recipient is optional.');
check(errorsFor(['emailNotificationRecipient' => 'media@example.com']) === [], 'Valid email recipient passes.');
check(isset(errorsFor(['emailNotificationRecipient' => 'not-an-email'])['emailNotificationRecipient']), 'Invalid email recipient is rejected.');

// Slack webhook URL: literal https URL or env/alias reference.
foreach (['', 'https://hooks.slack.com/services/T/B/X', '$SLACK_WEBHOOK_URL', '@slackWebhook'] as $url) {
	check(errorsFor(['slackWebhookUrl' => $url]) === [], "slackWebhookUrl must accept '$url'.");
}
foreach (['http://hooks.slack.com/services/T/B/X', 'hooks.slack.com', 'javascript:alert(1)'] as $url) {
	check(isset(errorsFor(['slackWebhookUrl' => $url])['slackWebhookUrl']), "slackWebhookUrl must reject '$url'.");
}

// Resolved secrets.
$settings = new Settings();
putenv('IMAGE_ENHANCER_TEST_SLACK_URL=https://hooks.slack.com/services/T/B/ENV');
putenv('IMAGE_ENHANCER_TEST_SLACK_TOKEN= xoxb-env ');
$settings->slackWebhookUrl = '$IMAGE_ENHANCER_TEST_SLACK_URL';
$settings->slackBotToken = '$IMAGE_ENHANCER_TEST_SLACK_TOKEN';
check($settings->getResolvedSlackWebhookUrl() === 'https://hooks.slack.com/services/T/B/ENV', 'Resolve webhook env var.');
check($settings->getResolvedSlackBotToken() === 'xoxb-env', 'Resolve and trim bot token env var.');
putenv('IMAGE_ENHANCER_TEST_SLACK_URL');
putenv('IMAGE_ENHANCER_TEST_SLACK_TOKEN');
check($settings->getResolvedSlackWebhookUrl() === '', 'Unset webhook env var resolves to empty string.');
check($settings->getResolvedSlackBotToken() === '', 'Unset bot token env var resolves to empty string.');
$settings->slackWebhookUrl = 'https://hooks.slack.com/services/T/B/LITERAL';
$settings->slackBotToken = '';
check($settings->getResolvedSlackWebhookUrl() === 'https://hooks.slack.com/services/T/B/LITERAL', 'Literal webhook is returned as-is.');
check($settings->getResolvedSlackBotToken() === '', 'Empty bot token stays empty.');
$settings->chatGptApiKey = '$IMAGE_ENHANCER_TEST_MISSING_KEY';
check($settings->getResolvedChatGptApiKey() === '', 'Unset API key env var resolves to empty string.');

echo "Passed {$checks} settings validation checks.\n";
