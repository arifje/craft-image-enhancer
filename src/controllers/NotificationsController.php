<?php

namespace arjanbrinkman\craftimageenhancer\controllers;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use Craft;
use craft\web\Controller;
use GuzzleHttp\Exception\RequestException;
use yii\web\Response;

class NotificationsController extends Controller
{
	/**
	 * Test sends are admin-only CP actions. `requireAdmin(false)` keeps them usable when
	 * allowAdminChanges is off, since they don't write project config.
	 *
	 * @inheritdoc
	 * @throws \yii\web\BadRequestHttpException
	 * @throws \yii\web\ForbiddenHttpException
	 */
	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) {
			return false;
		}

		$this->requireCpRequest();
		$this->requireAdmin(false);

		return true;
	}

	public function actionTestSlack(): Response
	{
		$this->requirePostRequest();
		$settings = ImageEnhancer::getInstance()->getSettings();
		$webhookUrl = $settings->getResolvedSlackWebhookUrl();
		$botToken = $settings->getResolvedSlackBotToken();

		$primaryChannel = trim($settings->slackChannel);
		$errorChannel = trim($settings->slackErrorChannel) ?: $primaryChannel;
		$sendPrimaryTest = $settings->slackNotification;
		$sendErrorTest = $settings->slackErrorNotification || trim($settings->slackErrorChannel) !== '';

		if (!$sendPrimaryTest && !$sendErrorTest) {
			return $this->asTestFailure('Slack notifications are disabled.');
		}

		$primaryBlocks = $this->getSlackTestBlocks(
			'Beeldkwaliteit test',
			'Score: test · Vervanging: test notification'
		);
		$errorBlocks = $this->getSlackTestBlocks(
			'Image enhancement error test',
			'This test checks the configured Slack error channel.'
		);

		try {
			$client = Craft::createGuzzleClient();
			$sent = 0;

			if ($webhookUrl !== '') {
				if ($sendPrimaryTest) {
					$this->sendWebhookSlackTest($client, $webhookUrl, 'Beeldkwaliteit test', $primaryBlocks, $primaryChannel);
					$sent++;
				}
				if ($sendErrorTest && (!$sendPrimaryTest || $errorChannel !== $primaryChannel)) {
					$this->sendWebhookSlackTest($client, $webhookUrl, 'Image enhancement error test', $errorBlocks, $errorChannel);
					$sent++;
				}

				return $this->asTestSuccess($sent === 1 ? 'Slack test notification sent via webhook.' : 'Slack test notifications sent via webhook.');
			}

			if ($botToken === '') {
				return $this->asTestFailure('Slack bot token or channel is missing.');
			}

			if ($sendPrimaryTest) {
				if ($primaryChannel === '') {
					return $this->asTestFailure('Slack channel is missing.');
				}
				$slackError = $this->sendBotSlackTest($client, $botToken, $primaryChannel, 'Beeldkwaliteit test', $primaryBlocks);
				if ($slackError !== null) {
					return $this->asSlackApiFailure($slackError);
				}
				$sent++;
			}
			if ($sendErrorTest && (!$sendPrimaryTest || $errorChannel !== $primaryChannel)) {
				if ($errorChannel === '') {
					return $this->asTestFailure('Slack error channel is missing.');
				}
				$slackError = $this->sendBotSlackTest($client, $botToken, $errorChannel, 'Image enhancement error test', $errorBlocks);
				if ($slackError !== null) {
					return $this->asSlackApiFailure($slackError);
				}
				$sent++;
			}

			return $this->asTestSuccess($sent === 1 ? 'Slack test notification sent via bot token.' : 'Slack test notifications sent via bot token.');
		} catch (\Throwable $e) {
			// Guzzle messages embed the request URI (the webhook URL is a secret); log a redacted summary.
			Craft::error('ImageEnhancer: Slack test notification failed: ' . self::describeFailure($e), __METHOD__);
			return $this->asTestFailure('Slack test notification failed. Check the Craft logs for details.');
		}
	}

	public function actionTestEmail(): Response
	{
		$this->requirePostRequest();
		$settings = ImageEnhancer::getInstance()->getSettings();
		$currentUser = Craft::$app->getUser()->getIdentity();
		$recipient = $settings->emailNotificationRecipient ?: $currentUser?->email;

		if (!$recipient) {
			return $this->asTestFailure('No email recipient configured and current user has no email address.');
		}

		try {
			$sent = Craft::$app->getMailer()->compose()
				->setTo($recipient)
				->setSubject('Beeldkwaliteit test')
				->setHtmlBody('<p><strong>Beeldkwaliteit test</strong></p><p>This test email was sent from Image Enhancer settings.</p>')
				->send();

			if (!$sent) {
				return $this->asTestFailure('Email test notification could not be sent.');
			}

			return $this->asTestSuccess('Email test notification sent to ' . $recipient . '.');
		} catch (\Throwable $e) {
			// Transport messages can include SMTP host/credential details; log a redacted summary.
			Craft::error('ImageEnhancer: Email test notification failed: ' . self::describeFailure($e), __METHOD__);
			return $this->asTestFailure('Email test notification failed. Check the Craft logs for details.');
		}
	}

	private function getSlackTestBlocks(string $title, string $message): array
	{
		return [
			[
				'type' => 'section',
				'text' => [
					'type' => 'mrkdwn',
					'text' => '*' . $title . "*\n" . $message,
				],
			],
			[
				'type' => 'context',
				'elements' => [[
					'type' => 'mrkdwn',
					'text' => 'Sent from Image Enhancer settings.',
				]],
			],
		];
	}

	private function sendWebhookSlackTest($client, string $webhookUrl, string $text, array $blocks, string $channel = ''): void
	{
		$payload = [
			'text' => $text,
			'blocks' => $blocks,
			'unfurl_links' => false,
			'unfurl_media' => false,
		];

		if ($channel !== '') {
			$payload['channel'] = $channel;
		}

		$client->post($webhookUrl, [
			'json' => $payload,
		]);
	}

	/**
	 * @return string|null The sanitized Slack error code, or null on success.
	 */
	private function sendBotSlackTest($client, string $botToken, string $channel, string $text, array $blocks): ?string
	{
		$response = $client->post('https://slack.com/api/chat.postMessage', [
			'headers' => [
				'Authorization' => 'Bearer ' . $botToken,
				'Content-Type' => 'application/json',
			],
			'json' => [
				'channel' => $channel,
				'text' => $text,
				'blocks' => $blocks,
				'unfurl_links' => false,
				'unfurl_media' => false,
			],
		]);
		$responseData = json_decode((string) $response->getBody(), true);

		if (($responseData['ok'] ?? true) === false) {
			return self::sanitizeSlackErrorCode($responseData['error'] ?? null);
		}

		return null;
	}

	/**
	 * Slack error codes (e.g. `channel_not_found`) carry no secrets, so they are safe to log and show.
	 */
	private function asSlackApiFailure(string $errorCode): Response
	{
		Craft::error('ImageEnhancer: Slack test notification rejected, Slack error code: ' . $errorCode, __METHOD__);

		return $this->asTestFailure('Slack rejected the test notification (' . $errorCode . ').');
	}

	/**
	 * Builds a log-safe failure summary: exception class and HTTP status code only. Never
	 * the message, which may contain the webhook URL, token, or transport credentials.
	 */
	private static function describeFailure(\Throwable $e): string
	{
		if ($e instanceof RequestException && $e->getResponse() !== null) {
			return get_class($e) . ', HTTP ' . $e->getResponse()->getStatusCode();
		}

		return get_class($e);
	}

	/**
	 * Slack error codes are snake_case identifiers (e.g. `channel_not_found`).
	 */
	private static function sanitizeSlackErrorCode(mixed $code): string
	{
		$code = is_string($code) ? (string) preg_replace('/[^a-z0-9_]/', '', strtolower($code)) : '';

		return $code !== '' ? substr($code, 0, 64) : 'unknown';
	}

	private function asTestSuccess(string $message): Response
	{
		return $this->asJson([
			'success' => true,
			'message' => $message,
		]);
	}

	private function asTestFailure(string $message): Response
	{
		return $this->asJson([
			'success' => false,
			'message' => $message,
		]);
	}
}

