<?php

declare(strict_types=1);

// Dependency-free regression checks. Only framework/HTTP boundaries are doubled;
// model discovery, option construction, and request validation use production code.
namespace craft\base {
    class Component {}
    class Model {}
    class Plugin
    {
        public static $instance;

        public static function getInstance()
        {
            return self::$instance;
        }
    }
}

namespace craft\helpers {
    class App
    {
        public static function parseEnv(string $value): string
        {
            return str_starts_with($value, '$') ? (string) getenv(substr($value, 1)) : $value;
        }
    }
}

namespace craft\web {
    class Controller {}
}

namespace {
    use arjanbrinkman\craftimageenhancer\controllers\ArticleImageController;
    use arjanbrinkman\craftimageenhancer\ImageEnhancer;
    use arjanbrinkman\craftimageenhancer\models\Settings;
    use arjanbrinkman\craftimageenhancer\services\AiImageEnhancementService;
    use arjanbrinkman\craftimageenhancer\services\OpenAiModelService;

    class Craft
    {
        public static $app;
        public static $client;
        public static array $warnings = [];

        public static function createGuzzleClient()
        {
            return self::$client;
        }

        public static function warning(string $message, string $method): void
        {
            self::$warnings[] = $message;
        }
    }

    require __DIR__ . '/../src/models/Settings.php';
    require __DIR__ . '/../src/services/OpenAiModelService.php';
    require __DIR__ . '/../src/services/AiImageEnhancementService.php';
    require __DIR__ . '/../src/ImageEnhancer.php';
    require __DIR__ . '/../src/controllers/ArticleImageController.php';

    $checks = 0;
    function check(bool $condition, string $message): void
    {
        global $checks;
        if (!$condition) {
            throw new RuntimeException($message);
        }
        $checks++;
    }

    $cache = new class {
        public int $now = 0;
        public array $entries = [];

        public function getOrSet(array $key, callable $callback, int $duration): array
        {
            $key = json_encode($key);
            if (!isset($this->entries[$key]) || $this->entries[$key]['expires'] <= $this->now) {
                $this->entries[$key] = ['value' => $callback(), 'expires' => $this->now + $duration];
            }
            return $this->entries[$key]['value'];
        }
    };
    $client = new class {
        public array $requests = [];
        public string $body = '{}';
        public bool $fail = false;

        public function get(string $url, array $options): object
        {
            $this->requests[] = compact('url', 'options');
            if ($this->fail) {
                throw new RuntimeException('Sensitive request details must not be logged.');
            }
            return new class($this->body) {
                public function __construct(private string $body) {}
                public function getBody(): string { return $this->body; }
            };
        }
    };
    $request = new class {
        public array $body = [];
        public function getBodyParam(string $name): mixed { return $this->body[$name] ?? null; }
    };
    Craft::$app = new class($cache, $request) {
        public function __construct(private object $cache, private object $request) {}
        public function getCache(): object { return $this->cache; }
        public function getRequest(): object { return $this->request; }
    };
    Craft::$client = $client;
    $settings = new Settings();
    $service = new OpenAiModelService();
    $plugin = new class extends ImageEnhancer {
        public Settings $settings;
        public OpenAiModelService $openAiModels;
        public function getSettings(): Settings { return $this->settings; }
    };
    $plugin->settings = $settings;
    $plugin->openAiModels = $service;
    \craft\base\Plugin::$instance = $plugin;

    $values = array_column($plugin->getImageEnhancementModelOptions(), 'value');
    check(in_array('gpt-image-2.5-sunburst', $values, true), 'Missing Sunburst fallback.');
    check(in_array('gpt-image-2.5-flare', $values, true), 'Missing Flare fallback.');
    check(count($client->requests) === 0, 'Missing credentials must not trigger discovery.');

    putenv('IMAGE_ENHANCER_TEST_KEY=test-account-a');
    $settings->chatGptApiKey = '$IMAGE_ENHANCER_TEST_KEY';
    $client->body = json_encode(['data' => [
        ['id' => 'gpt-image-2.5-sunburst'], ['id' => 'gpt-image-2.5-flare'],
        ['id' => 'gpt-image-2.5-sunburst-2026-09-08'], ['id' => 'gpt-image-2.5-flare'],
        ['id' => 'gpt-image-10'], ['id' => 'chatgpt-image-latest'],
        ['id' => 'gpt-5.5'], ['id' => 'dall-e-3'], ['id' => 'gpt-image-bad<script>'],
        ['id' => null], ['id' => 42], null,
    ]]);
    $options = $plugin->getImageEnhancementModelOptions();
    $values = array_column($options, 'value');
    check(count($client->requests) === 1, 'Discovery should run once.');
    check($client->requests[0]['url'] === 'https://api.openai.com/v1/models', 'Wrong discovery endpoint.');
    check($client->requests[0]['options']['headers']['Authorization'] === 'Bearer test-account-a', 'Resolve environment credentials.');
    check($client->requests[0]['options']['timeout'] === 5, 'Discovery needs a bounded timeout.');
    check($values[0] === 'gpt-image-10', 'Models should use descending natural order.');
    check(count(array_keys($values, 'gpt-image-2.5-flare', true)) === 1, 'Deduplicate model IDs.');
    check(in_array('gpt-image-2.5-sunburst-2026-09-08', $values, true), 'Keep exact snapshot IDs.');
    check(in_array('chatgpt-image-latest', $values, true), 'Keep supported image alias.');
    check(!in_array('gpt-5.5', $values, true) && !in_array('dall-e-3', $values, true), 'Exclude incompatible model families.');
    check(!in_array('gpt-image-bad<script>', $values, true), 'Reject malformed IDs.');
    check(!in_array('gpt-image-1', $values, true), 'Do not merge fallback choices into successful discovery.');
    check(in_array('gpt-image-2', $values, true), 'Preserve configured model when discovery omits it.');
    check(array_column($options, 'label', 'value')['gpt-image-2.5-sunburst'] === 'GPT Image 2.5 Sunburst', 'Use readable labels.');
    check(!in_array('gpt-image-2.5-sunburst', array_column($plugin->getChatGptModelOptions(), 'value'), true), 'Image models must not enter the ChatGPT selector.');
    check(count($client->requests) === 1, 'ChatGPT and image selectors must share the cache.');
    check(!str_contains(json_encode($cache->entries), 'test-account-a'), 'Do not store credentials in cache keys or values.');

    $settings->imageEnhancementProvider = Settings::IMAGE_PROVIDER_FRONTEND;
    $request->body = ['imageEnhancementProvider' => 'openai', 'imageEnhancementModel' => 'gpt-image-2.5-sunburst'];
    $validate = new ReflectionMethod(ArticleImageController::class, 'getProviderOptionsForRequest');
    $controller = new ArticleImageController();
    $providerOptions = $validate->invoke($controller, $settings);
    check(is_array($providerOptions), 'Discovered models must pass frontend request validation.');
    check((new AiImageEnhancementService())->getProviderModel($settings, $providerOptions) === 'gpt-image-2.5-sunburst', 'Do not rewrite the selected model.');
    $request->body['imageEnhancementModel'] = 'gpt-image-999';
    check($validate->invoke($controller, $settings) === false, 'Reject unlisted frontend model IDs.');

    $client->body = '{"data":[{"id":"gpt-image-3"}]}';
    $cache->now = 899;
    check(!in_array('gpt-image-3', array_column($plugin->getImageEnhancementModelOptions(), 'value'), true), 'Cache must last 15 minutes.');
    $cache->now = 900;
    check(in_array('gpt-image-3', array_column($plugin->getImageEnhancementModelOptions(), 'value'), true), 'Refresh after 15 minutes.');
    check(count($client->requests) === 2, 'Expiry should cause exactly one new lookup.');

    putenv('IMAGE_ENHANCER_TEST_KEY=test-account-b');
    $plugin->getImageEnhancementModelOptions();
    check(count($client->requests) === 3, 'Resolved credential rotation must bypass the old cache.');

    $client->fail = true;
    $cache->now = 1800;
    $settings->imageEnhancementModel = 'gpt-image-3';
    $values = array_column($plugin->getImageEnhancementModelOptions(), 'value');
    check(in_array('gpt-image-3', $values, true) && in_array('gpt-image-2.5-sunburst', $values, true), 'Outages must preserve selection and provide fallbacks.');
    $plugin->getImageEnhancementModelOptions();
    check(count($client->requests) === 4, 'Cache failures to prevent repeated slow requests.');
    check(!str_contains(implode(' ', Craft::$warnings), 'Sensitive'), 'Do not log exception details.');

    $client->fail = false;
    foreach (['invalid json', '{"data":null}', '{"data":[]}', '{"data":[{"id":"gpt-5.5"}]}'] as $body) {
        $cache->now += 900;
        $client->body = $body;
        check(in_array('gpt-image-2.5-flare', array_column($plugin->getImageEnhancementModelOptions(), 'value'), true), 'Malformed or empty discovery must fall back.');
    }
    $cache->now += 900;
    $client->body = '{"data":[{"id":"gpt-image-4"}]}';
    check(in_array('gpt-image-4', array_column($plugin->getImageEnhancementModelOptions(), 'value'), true), 'Discovery must recover after an outage.');
    putenv('IMAGE_ENHANCER_TEST_KEY');

    echo "Passed {$checks} OpenAI model regression checks.\n";
}
