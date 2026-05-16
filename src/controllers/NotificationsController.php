<?php

namespace arjanbrinkman\craftimagequalitychecker\controllers;

use arjanbrinkman\craftimagequalitychecker\ImageQualityChecker;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class NotificationsController extends Controller
{
	public function actionTestSlack(): Response
	{
		$this->requirePostRequest();
		$settings = ImageQualityChecker::getInstance()->getSettings();

		if (!$settings->slackNotification) {
			return $this->asTestFailure('Slack notifications are disabled.');
		}

		$blocks = [
			[
				'type' => 'section',
				'text' => [
					'type' => 'mrkdwn',
					'text' => "*Beeldkwaliteit test*\nScore: test · Vervanging: test notification",
				],
			],
			[
				'type' => 'context',
				'elements' => [[
					'type' => 'mrkdwn',
					'text' => 'Sent from Image Quality Checker settings.',
				]],
			],
		];

		try {
			$client = Craft::createGuzzleClient();

			if ($settings->slackWebhookUrl) {
				$client->post($settings->slackWebhookUrl, [
					'json' => [
						'text' => 'Beeldkwaliteit test',
						'blocks' => $blocks,
						'unfurl_links' => false,
						'unfurl_media' => false,
					],
				]);

				return $this->asTestSuccess('Slack test notification sent via webhook.');
			}

			if (!$settings->slackBotToken || !$settings->slackChannel) {
				return $this->asTestFailure('Slack bot token or channel is missing.');
			}

			$response = $client->post('https://slack.com/api/chat.postMessage', [
				'headers' => [
					'Authorization' => 'Bearer ' . $settings->slackBotToken,
					'Content-Type' => 'application/json',
				],
				'json' => [
					'channel' => $settings->slackChannel,
					'text' => 'Beeldkwaliteit test',
					'blocks' => $blocks,
					'unfurl_links' => false,
					'unfurl_media' => false,
				],
			]);
			$responseData = json_decode((string) $response->getBody(), true);

			if (($responseData['ok'] ?? true) === false) {
				return $this->asTestFailure('Slack API error: ' . ($responseData['error'] ?? 'unknown'));
			}

			return $this->asTestSuccess('Slack test notification sent via bot token.');
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Slack test notification failed: ' . $e->getMessage(), __METHOD__);
			return $this->asTestFailure('Slack test notification failed: ' . $e->getMessage());
		}
	}

	public function actionTestEmail(): Response
	{
		$this->requirePostRequest();
		$settings = ImageQualityChecker::getInstance()->getSettings();
		$currentUser = Craft::$app->getUser()->getIdentity();
		$recipient = $settings->emailNotificationRecipient ?: $currentUser?->email;

		if (!$recipient) {
			return $this->asTestFailure('No email recipient configured and current user has no email address.');
		}

		try {
			$sent = Craft::$app->getMailer()->compose()
				->setTo($recipient)
				->setSubject('Beeldkwaliteit test')
				->setHtmlBody('<p><strong>Beeldkwaliteit test</strong></p><p>This test email was sent from Image Quality Checker settings.</p>')
				->send();

			if (!$sent) {
				return $this->asTestFailure('Email test notification could not be sent.');
			}

			return $this->asTestSuccess('Email test notification sent to ' . $recipient . '.');
		} catch (\Throwable $e) {
			Craft::error('ImageQualityChecker: Email test notification failed: ' . $e->getMessage(), __METHOD__);
			return $this->asTestFailure('Email test notification failed: ' . $e->getMessage());
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
