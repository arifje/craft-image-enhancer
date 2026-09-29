<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\models\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * OpenAI chat-model selection and chat completion requests for image checks.
 */
class ChatModelHelper
{
	// Const Properties
	// =========================================================================

	public const FALLBACK_MODEL = 'gpt-4o';
	public const REASONING_MIN_COMPLETION_TOKENS = 2000;
	public const CHAT_COMPLETIONS_URL = 'https://api.openai.com/v1/chat/completions';

	// Public Methods
	// =========================================================================

	/**
	 * Whether a model can be used for the vision chat requests. `-pro` models are
	 * Responses-API only and slow/expensive, so they are never picked.
	 */
	public static function isUsableChatModel(string $model): bool
	{
		return Settings::isSupportedChatGptModel($model) && preg_match('/-pro(?:-|$)/', $model) !== 1;
	}

	/**
	 * Picks the newest usable model from a list of model IDs.
	 *
	 * @param string[] $modelIds
	 */
	public static function pickLatestModel(array $modelIds): ?string
	{
		$models = array_values(array_filter(
			$modelIds,
			static fn(mixed $model): bool => is_string($model) && self::isUsableChatModel($model),
		));

		usort($models, static fn(string $a, string $b): int => self::modelSortScore($b) <=> self::modelSortScore($a));

		return $models[0] ?? null;
	}

	/**
	 * Resolves the configured chat model, using the cached model list for "latest".
	 */
	public static function resolveModel(Settings $settings): string
	{
		if ($settings->chatGptModel !== Settings::MODEL_LATEST) {
			return $settings->chatGptModel;
		}

		return self::pickLatestModel(ImageEnhancer::getInstance()->openAiModels->getModels($settings)) ?? self::FALLBACK_MODEL;
	}

	public static function modelSortScore(string $model): int
	{
		if (preg_match('/^gpt-(\d+)(?:\.(\d+))?/', $model, $matches) === 1) {
			$major = (int) $matches[1];
			$minor = (int) ($matches[2] ?? 0);
			$sizePenalty = str_contains($model, 'nano') ? 20 : (str_contains($model, 'mini') ? 10 : 0);
			// Prefer the alias over dated snapshots and preview builds of the same model.
			$variantPenalty = preg_match('/-\d{4}-\d{2}-\d{2}$|preview/', $model) === 1 ? 1 : 0;

			return ($major * 1000) + ($minor * 10) - $sizePenalty - $variantPenalty;
		}

		return 0;
	}

	/**
	 * Whether the model is a reasoning model (gpt-5 family, o-series) that spends
	 * completion tokens on reasoning. `-chat` variants are non-reasoning.
	 */
	public static function isReasoningModel(string $model): bool
	{
		return preg_match('/^(?:gpt-5|o\d)/', $model) === 1 && !str_contains($model, '-chat');
	}

	/**
	 * Returns the lowest supported reasoning effort for a model, or null for non-reasoning models.
	 * Only the original gpt-5 family supports "minimal"; later versions and o-series accept "low".
	 */
	public static function getReasoningEffort(string $model): ?string
	{
		if (!self::isReasoningModel($model)) {
			return null;
		}

		return preg_match('/^gpt-5(?:-mini|-nano)?(?:-\d{4}-\d{2}-\d{2})?$/', $model) === 1 ? 'minimal' : 'low';
	}

	/**
	 * Builds a chat completion payload with a token budget that leaves room for reasoning.
	 */
	public static function buildPayload(string $model, array $messages, int $maxCompletionTokens, array $extra = []): array
	{
		$payload = array_merge([
			'model' => $model,
			'messages' => $messages,
			'max_completion_tokens' => $maxCompletionTokens,
		], $extra);

		$effort = self::getReasoningEffort($model);
		if ($effort !== null) {
			$payload['reasoning_effort'] = $effort;
			$payload['max_completion_tokens'] = max(self::REASONING_MIN_COMPLETION_TOKENS, $maxCompletionTokens * 2);
		}

		return $payload;
	}

	/**
	 * Sends a chat completion and returns the decoded response. When the API rejects the
	 * reasoning effort parameter, the request is repeated once without it.
	 *
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \RuntimeException if the response is not JSON
	 */
	public static function createCompletion(ClientInterface $client, string $apiKey, array $payload): array
	{
		try {
			$response = self::send($client, $apiKey, $payload);
		} catch (RequestException $e) {
			if (!isset($payload['reasoning_effort']) || HttpHelper::getStatusCode($e) !== 400 || !self::mentionsReasoningEffort($e)) {
				throw $e;
			}

			unset($payload['reasoning_effort']);
			$response = self::send($client, $apiKey, $payload);
		}

		$data = json_decode((string) $response->getBody(), true);
		if (!is_array($data)) {
			throw new \RuntimeException('OpenAI returned an invalid response.');
		}

		return $data;
	}

	// Private Methods
	// =========================================================================

	private static function send(ClientInterface $client, string $apiKey, array $payload): \Psr\Http\Message\ResponseInterface
	{
		return $client->request('POST', self::CHAT_COMPLETIONS_URL, HttpHelper::withTimeouts(HttpHelper::CHAT_TIMEOUT, [
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
				'Content-Type' => 'application/json',
			],
			'json' => $payload,
		]));
	}

	private static function mentionsReasoningEffort(RequestException $e): bool
	{
		$response = $e->getResponse();
		if ($response === null) {
			return false;
		}

		$body = $response->getBody();
		if ($body->isSeekable()) {
			$body->rewind();
		}

		return str_contains($body->read(65536), 'reasoning_effort');
	}
}
