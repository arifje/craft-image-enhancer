<?php

namespace arjanbrinkman\craftimageenhancer\controllers;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class NotificationsController extends Controller
{
	public function actionTestSlack(): Response
	{
		$this->requirePostRequest();
		$settings = ImageEnhancer::getInstance()->getSettings();

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

			if ($settings->slackWebhookUrl) {
				if ($sendPrimaryTest) {
					$this->sendWebhookSlackTest($client, $settings->slackWebhookUrl, 'Beeldkwaliteit test', $primaryBlocks, $primaryChannel);
					$sent++;
				}
				if ($sendErrorTest && (!$sendPrimaryTest || $errorChannel !== $primaryChannel)) {
					$this->sendWebhookSlackTest($client, $settings->slackWebhookUrl, 'Image enhancement error test', $errorBlocks, $errorChannel);
					$sent++;
				}

				return $this->asTestSuccess($sent === 1 ? 'Slack test notification sent via webhook.' : 'Slack test notifications sent via webhook.');
			}

			if (!$settings->slackBotToken) {
				return $this->asTestFailure('Slack bot token or channel is missing.');
			}

			if ($sendPrimaryTest) {
				if ($primaryChannel === '') {
					return $this->asTestFailure('Slack channel is missing.');
				}
				$this->sendBotSlackTest($client, $settings->slackBotToken, $primaryChannel, 'Beeldkwaliteit test', $primaryBlocks);
				$sent++;
			}
			if ($sendErrorTest && (!$sendPrimaryTest || $errorChannel !== $primaryChannel)) {
				if ($errorChannel === '') {
					return $this->asTestFailure('Slack error channel is missing.');
				}
				$this->sendBotSlackTest($client, $settings->slackBotToken, $errorChannel, 'Image enhancement error test', $errorBlocks);
				$sent++;
			}

			return $this->asTestSuccess($sent === 1 ? 'Slack test notification sent via bot token.' : 'Slack test notifications sent via bot token.');
		} catch (\Throwable $e) {
			Craft::error('ImageEnhancer: Slack test notification failed: ' . $e->getMessage(), __METHOD__);
			return $this->asTestFailure('Slack test notification failed: ' . $e->getMessage());
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
			Craft::error('ImageEnhancer: Email test notification failed: ' . $e->getMessage(), __METHOD__);
			return $this->asTestFailure('Email test notification failed: ' . $e->getMessage());
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

	private function sendBotSlackTest($client, string $botToken, string $channel, string $text, array $blocks): void
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
			throw new \RuntimeException('Slack API error: ' . ($responseData['error'] ?? 'unknown'));
		}
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
