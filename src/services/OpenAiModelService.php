<?php

declare(strict_types=1);

namespace arjanbrinkman\craftimageenhancer\services;

use arjanbrinkman\craftimageenhancer\models\Settings;
use Craft;
use craft\base\Component;

class OpenAiModelService extends Component
{
    private const CACHE_DURATION = 900;

    /** @return string[] */
    public function getModels(Settings $settings): array
    {
        $apiKey = $settings->getResolvedChatGptApiKey();
        if ($apiKey === '') {
            return [];
        }

        // Isolate accounts without putting credentials into cache keys or values.
        $cacheKey = ['craft-image-enhancer', 'openai-models', hash('sha256', $apiKey)];

        return Craft::$app->getCache()->getOrSet($cacheKey, static function() use ($apiKey): array {
            try {
                $response = Craft::createGuzzleClient()->get('https://api.openai.com/v1/models', [
                    'headers' => ['Authorization' => 'Bearer ' . $apiKey],
                    'connect_timeout' => 3,
                    'timeout' => 5,
                ]);
                $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
                    throw new \UnexpectedValueException('Invalid OpenAI model list.');
                }

                $models = [];
                foreach ($data['data'] as $model) {
                    $id = is_array($model) ? ($model['id'] ?? null) : null;
                    if (is_string($id) && $id !== '') {
                        $models[] = $id;
                    }
                }

                return array_values(array_unique($models));
            } catch (\Throwable $e) {
                // Exception messages may contain request credentials or response details.
                Craft::warning('ImageEnhancer: Could not fetch OpenAI models; using fallback options for 15 minutes.', __METHOD__);

                return [];
            }
        }, self::CACHE_DURATION);
    }

    public function getImageModelOptions(Settings $settings): array
    {
        $models = array_values(array_filter($this->getModels($settings), [Settings::class, 'isSupportedImageEnhancementModel']));
        if ($models === []) {
            $models = array_column(Settings::imageEnhancementModelOptions(), 'value');
        }

        // Keep the configured choice even if discovery is unavailable or omits it.
        if (Settings::isSupportedImageEnhancementModel($settings->imageEnhancementModel)) {
            $models[] = $settings->imageEnhancementModel;
        }

        $models = array_values(array_unique($models));
        usort($models, static fn(string $a, string $b): int => strnatcasecmp($b, $a));

        return array_map(static fn(string $model): array => [
            'label' => $model === 'chatgpt-image-latest'
                ? 'ChatGPT Image Latest'
                : 'GPT Image ' . ucwords(str_replace('-', ' ', substr($model, strlen('gpt-image-')))),
            'value' => $model,
        ], $models);
    }
}
