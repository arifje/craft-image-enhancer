<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

use arjanbrinkman\craftimageenhancer\models\Settings;
use Craft;

/**
 * Slack delivery and mrkdwn escaping. Delivery failures are logged without exception
 * messages, which would contain the webhook URL.
 */
class SlackHelper
{
	// Const Properties
	// =========================================================================

	public const POST_MESSAGE_URL = 'https://slack.com/api/chat.postMessage';

	// Public Methods
	// =========================================================================

	/**
	 * Escapes text for Slack mrkdwn. `|` is replaced because it ends a link label.
	 */
	public static function escape(string $text): string
	{
		return str_replace(['&', '<', '>', '|'], ['&amp;', '&lt;', '&gt;', '/'], $text);
	}

	/**
	 * Returns a Slack link, or the escaped label when the URL is not http(s).
	 */
	public static function link(string $url, string $label): string
	{
		if (preg_match('#^https?://#i', $url) !== 1) {
			return self::escape($label);
		}

		return '<' . str_replace(['>', '|', ' '], ['%3E', '%7C', '%20'], $url) . '|' . self::escape($label) . '>';
	}

	/**
	 * Sends a message via the webhook, or via the bot token when no webhook is configured.
	 *
	 * @param array $message Slack message fields (text, blocks, unfurl_*)
	 * @param string|null $channel Channel for the bot API (and the webhook when $channelForWebhook)
	 */
	public static function send(Settings $settings, array $message, ?string $channel = null, bool $channelForWebhook = false): bool
	{
		$webhookUrl = $settings->getResolvedSlackWebhookUrl();
		$botToken = $settings->getResolvedSlackBotToken();
		$channel = trim((string) $channel);

		try {
			$client = Craft::createGuzzleClient();

			if ($webhookUrl !== '') {
				if ($channelForWebhook && $channel !== '') {
					$message['channel'] = $channel;
				}

				$client->request('POST', $webhookUrl, HttpHelper::withTimeouts(HttpHelper::NOTIFICATION_TIMEOUT, [
					'json' => $message,
				]));

				return true;
			}

			if ($botToken === '' || $channel === '') {
				Craft::warning('ImageEnhancer: Slack notification skipped because no webhook, bot token or channel is configured.', __METHOD__);

				return false;
			}

			$response = $client->request('POST', self::POST_MESSAGE_URL, HttpHelper::withTimeouts(HttpHelper::NOTIFICATION_TIMEOUT, [
				'headers' => [
					'Authorization' => 'Bearer ' . $botToken,
					'Content-Type' => 'application/json',
				],
				'json' => array_merge($message, ['channel' => $channel]),
			]));
			$data = json_decode((string) $response->getBody(), true);
			if (is_array($data) && ($data['ok'] ?? true) === false) {
				$error = is_string($data['error'] ?? null) ? preg_replace('/[^a-z0-9_]/i', '', $data['error']) : 'unknown';
				Craft::warning('ImageEnhancer: Slack API error: ' . $error, __METHOD__);

				return false;
			}

			return true;
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Slack notification failed: ' . HttpHelper::describe($e), __METHOD__);

			return false;
		}
	}
}
