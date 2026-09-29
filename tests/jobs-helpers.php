<?php

declare(strict_types=1);

// Dependency-free regression checks for the job/service helpers. Craft/Yii classes are
// stubbed; Guzzle and PSR-7 are the real vendor packages so HTTP behaviour is realistic.
namespace craft\base {
    class Model {}
}

namespace craft\helpers {
    class App
    {
        public static function parseEnv(string $value): string
        {
            return $value;
        }
    }
}

namespace yii\base {
    class Component {}
}

namespace craft\elements {
    class Asset
    {
        public ?int $width = null;
        public ?int $height = null;
        public ?int $size = null;
    }
}

namespace craft\elements\conditions\assets {
    class FileSizeConditionRule
    {
        public const UNIT_B = 'B';
        public const UNIT_KB = 'KB';
        public const UNIT_MB = 'MB';
        public const UNIT_GB = 'GB';
    }
}

namespace {
    use arjanbrinkman\craftimageenhancer\helpers\AnalysisResultHelper;
    use arjanbrinkman\craftimageenhancer\helpers\AssetHelper;
    use arjanbrinkman\craftimageenhancer\helpers\ChatModelHelper;
    use arjanbrinkman\craftimageenhancer\helpers\FaceBoxHelper;
    use arjanbrinkman\craftimageenhancer\helpers\HttpHelper;
    use arjanbrinkman\craftimageenhancer\helpers\ImageHelper;
    use arjanbrinkman\craftimageenhancer\services\AssetRequirementService;
    use craft\elements\Asset;
    use GuzzleHttp\ClientInterface;
    use GuzzleHttp\Exception\ClientException;
    use GuzzleHttp\Exception\ConnectException;
    use GuzzleHttp\Exception\ServerException;
    use GuzzleHttp\Promise\PromiseInterface;
    use GuzzleHttp\Psr7\Request;
    use GuzzleHttp\Psr7\Response;
    use Psr\Http\Message\RequestInterface;
    use Psr\Http\Message\ResponseInterface;

    $root = dirname(__DIR__);
    $prefixes = [
        'arjanbrinkman\\craftimageenhancer\\' => $root . '/src/',
        'GuzzleHttp\\Psr7\\' => $root . '/vendor/guzzlehttp/psr7/src/',
        'GuzzleHttp\\Promise\\' => $root . '/vendor/guzzlehttp/promises/src/',
        'GuzzleHttp\\' => $root . '/vendor/guzzlehttp/guzzle/src/',
        'Psr\\Http\\Message\\' => $root . '/vendor/psr/http-message/src/',
        'Psr\\Http\\Client\\' => $root . '/vendor/psr/http-client/src/',
    ];
    spl_autoload_register(static function(string $class) use ($prefixes): void {
        foreach ($prefixes as $prefix => $directory) {
            if (str_starts_with($class, $prefix)) {
                $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    });

    class Craft
    {
        public static function warning(string $message, string $method): void {}
        public static function info(string $message, string $method): void {}
    }

    $checks = 0;
    function check(bool $condition, string $message): void
    {
        global $checks;
        if (!$condition) {
            throw new RuntimeException('FAILED: ' . $message);
        }
        $checks++;
    }

    function throws(callable $fn): ?Throwable
    {
        try {
            $fn();
        } catch (Throwable $e) {
            return $e;
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Quality score parsing (I6)
    // ---------------------------------------------------------------------

    $parsed = AnalysisResultHelper::parse('{"score": 42, "reason": "Blurry"}');
    check($parsed['score'] === 42 && $parsed['reason'] === 'Blurry' && $parsed['valid'], 'Parse a valid result.');
    $parsed = AnalysisResultHelper::parse("Sure!\n```json\n{\"score\": \"73\", \"reason\": \"Fine\"}\n```");
    check($parsed['score'] === 73, 'Parse JSON embedded in text and integer strings.');
    check(AnalysisResultHelper::parse('{"score": 101, "reason": "x"}')['score'] === null, 'Reject scores above 100.');
    check(AnalysisResultHelper::parse('{"score": -1, "reason": "x"}')['score'] === null, 'Reject negative scores.');
    check(AnalysisResultHelper::parse('{"score": "abc", "reason": "x"}')['score'] === null, 'Reject non-numeric scores.');
    check(AnalysisResultHelper::parse('{"score": 55.5, "reason": "x"}')['score'] === null, 'Reject fractional scores.');
    check(AnalysisResultHelper::parse('{"score": 60.0, "reason": "x"}')['score'] === 60, 'Accept integral floats.');
    check(AnalysisResultHelper::parse('{"score": true, "reason": "x"}')['score'] === null, 'Reject booleans.');
    check(AnalysisResultHelper::parse('{"score": null}')['score'] === null, 'Missing score is unknown.');
    check(AnalysisResultHelper::parse('{"score": 10, "reason": ["x"]}')['reason'] === '', 'Non-string reasons are dropped.');
    $parsed = AnalysisResultHelper::parse('I cannot rate this image.');
    check($parsed['score'] === null && !$parsed['valid'] && $parsed['reason'] === 'I cannot rate this image.', 'Non-JSON answers are unknown.');
    check(mb_strlen(AnalysisResultHelper::parse('{"score": 1, "reason": "' . str_repeat('a', 5000) . '"}')['reason']) === AnalysisResultHelper::MAX_REASON_LENGTH, 'Truncate long reasons.');
    check(AnalysisResultHelper::getScoreBand(null)['label'] === 'Onbekend', 'Unknown band is reachable.');
    check(AnalysisResultHelper::getScoreBand(0)['label'] === 'Slecht', 'Zero is a valid bad score.');
    check(AnalysisResultHelper::getScoreBand(59)['label'] === 'Matig', 'Band boundary 59.');
    check(AnalysisResultHelper::getScoreBand(100)['label'] === 'Uitstekend', 'Band boundary 100.');
    check(!AnalysisResultHelper::isBelowThreshold(null, 50), 'Unknown scores never trigger enhancement.');
    check(AnalysisResultHelper::isBelowThreshold(50, 50) && !AnalysisResultHelper::isBelowThreshold(51, 50), 'Threshold is inclusive.');

    // ---------------------------------------------------------------------
    // Chat model selection and reasoning options (I6)
    // ---------------------------------------------------------------------

    require $root . '/src/models/Settings.php';
    check(!ChatModelHelper::isUsableChatModel('gpt-5-pro'), 'Exclude -pro models.');
    check(!ChatModelHelper::isUsableChatModel('gpt-5-pro-2025-10-06'), 'Exclude dated -pro models.');
    check(ChatModelHelper::isUsableChatModel('gpt-5.5'), 'Allow regular gpt-5 models.');
    check(ChatModelHelper::pickLatestModel(['gpt-4o', 'gpt-5-pro', 'gpt-5.5-pro', 'gpt-5.4', 'gpt-5.5-mini', 'gpt-image-2', 'dall-e-3']) === 'gpt-5.4', 'Pick the newest usable full model.');
    check(ChatModelHelper::pickLatestModel(['gpt-5.5-2026-01-01', 'gpt-5.5']) === 'gpt-5.5', 'Prefer the alias over a dated snapshot.');
    check(ChatModelHelper::pickLatestModel([]) === null, 'No models yields null.');
    check(ChatModelHelper::getReasoningEffort('gpt-5') === 'minimal', 'gpt-5 supports minimal effort.');
    check(ChatModelHelper::getReasoningEffort('gpt-5-mini-2025-08-07') === 'minimal', 'gpt-5-mini snapshots support minimal effort.');
    check(ChatModelHelper::getReasoningEffort('gpt-5.5') === 'low', 'Later gpt-5.x use low effort.');
    check(ChatModelHelper::getReasoningEffort('gpt-5-chat-latest') === null, 'Chat variants are not reasoning models.');
    check(ChatModelHelper::getReasoningEffort('gpt-4o') === null, 'gpt-4o is not a reasoning model.');
    $payload = ChatModelHelper::buildPayload('gpt-5.5', [], 500);
    check($payload['reasoning_effort'] === 'low' && $payload['max_completion_tokens'] === 2000, 'Reasoning models get effort and a larger budget.');
    $payload = ChatModelHelper::buildPayload('gpt-4o', [], 500, ['response_format' => ['type' => 'json_object']]);
    check(!isset($payload['reasoning_effort']) && $payload['max_completion_tokens'] === 500 && isset($payload['response_format']), 'Non-reasoning models keep the default budget.');

    $fakeClient = new class implements ClientInterface {
        /** @var array<int, array{method: string, url: string, options: array}> */
        public array $requests = [];
        /** @var callable */
        public $handler;

        public function send(RequestInterface $request, array $options = []): ResponseInterface { throw new LogicException(); }
        public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface { throw new LogicException(); }
        public function requestAsync(string $method, $uri, array $options = []): PromiseInterface { throw new LogicException(); }
        public function getConfig(?string $option = null) { return null; }
        public function request(string $method, $uri, array $options = []): ResponseInterface
        {
            $this->requests[] = ['method' => $method, 'url' => (string) $uri, 'options' => $options];
            return ($this->handler)($method, (string) $uri, $options, count($this->requests));
        }
    };

    $fakeClient->handler = static function(string $method, string $url, array $options, int $call): ResponseInterface {
        if ($call === 1) {
            throw new ClientException('bad', new Request('POST', $url), new Response(400, [], '{"error":{"message":"Unsupported value: reasoning_effort"}}'));
        }
        return new Response(200, [], '{"choices":[{"message":{"content":"{}"}}]}');
    };
    $result = ChatModelHelper::createCompletion($fakeClient, 'key', ChatModelHelper::buildPayload('gpt-5.5', [], 500));
    check(count($fakeClient->requests) === 2, 'Retry once when reasoning_effort is rejected.');
    check(isset($fakeClient->requests[0]['options']['json']['reasoning_effort']) && !isset($fakeClient->requests[1]['options']['json']['reasoning_effort']), 'Retry drops reasoning_effort.');
    check($fakeClient->requests[0]['options']['timeout'] === HttpHelper::CHAT_TIMEOUT && isset($fakeClient->requests[0]['options']['connect_timeout']), 'Chat requests are time-bounded.');
    check(isset($result['choices']), 'Return decoded completion.');

    $fakeClient->requests = [];
    $fakeClient->handler = static function(string $method, string $url): ResponseInterface {
        throw new ClientException('bad', new Request('POST', $url), new Response(400, [], '{"error":{"message":"Invalid image"}}'));
    };
    check(throws(static fn() => ChatModelHelper::createCompletion($fakeClient, 'key', ChatModelHelper::buildPayload('gpt-5.5', [], 500))) instanceof ClientException, 'Other 400 errors are not retried.');
    check(count($fakeClient->requests) === 1, 'Other 400 errors make one request.');

    // ---------------------------------------------------------------------
    // Face boxes (I9)
    // ---------------------------------------------------------------------

    $faces = FaceBoxHelper::normalizeFaceBoxes(['faces' => [
        ['x' => 100, 'y' => 200, 'width' => 150, 'height' => 180, 'confidence' => 'high'],
        ['x' => '995', 'y' => 10, 'width' => 100, 'height' => 100],
        ['x' => -20, 'y' => 1200, 'width' => 50, 'height' => 50],
        ['x' => 'NaN', 'y' => 10, 'width' => 10, 'height' => 10],
        ['x' => 10, 'y' => 10, 'width' => 3, 'height' => 30],
        'not a face',
        ['x' => 900, 'y' => 900, 'width' => 400, 'height' => 400, 'confidence' => ['x']],
    ]]);
    check(count($faces) === 3, 'Drop invalid, degenerate and clipped-to-nothing boxes.');
    check($faces[0] === ['x' => 100, 'y' => 200, 'width' => 150, 'height' => 180, 'confidence' => 'high', 'source' => ''], 'Keep valid boxes.');
    check($faces[1]['x'] === 995 && $faces[1]['width'] === 5, 'Clamp boxes to the right edge of the 0-1000 grid.');
    check($faces[2]['width'] === 100 && $faces[2]['height'] === 100 && $faces[2]['confidence'] === '', 'Clamp oversized boxes and drop non-scalar confidence.');
    check(FaceBoxHelper::normalizeFaceBoxes(null) === [] && FaceBoxHelper::normalizeFaceBoxes(['faces' => 'x']) === [], 'Invalid data yields no faces.');
    $many = array_fill(0, 80, ['x' => 1, 'y' => 1, 'width' => 10, 'height' => 10]);
    check(count(FaceBoxHelper::normalizeFaceBoxes(['faces' => $many])) === FaceBoxHelper::MAX_FACES, 'Cap faces at MAX_FACES.');
    check(FaceBoxHelper::normalizeManualFaceBoxes([['x' => 1, 'y' => 1, 'width' => 10, 'height' => 10]])[0]['source'] === 'manual', 'Manual boxes are marked.');

    $manual = FaceBoxHelper::toPixels(['x' => 100, 'y' => 250, 'width' => 200, 'height' => 500, 'source' => 'manual'], 2000, 1000);
    check($manual === ['x' => 200, 'y' => 250, 'width' => 400, 'height' => 500], 'Manual boxes map 1:1 to pixels.');
    // Normalized boxes stay valid on any rendition size (e.g. a 2048px detection copy).
    $small = FaceBoxHelper::toPixels(['x' => 100, 'y' => 250, 'width' => 200, 'height' => 500, 'source' => 'manual'], 1000, 500);
    check($small['x'] * 2 === $manual['x'] && $small['width'] * 2 === $manual['width'], 'Normalized boxes scale with the image.');
    foreach ([[0, 0, 1000, 1000], [950, 950, 50, 50], [0, 500, 30, 500], [400, 0, 600, 20]] as [$x, $y, $w, $h]) {
        $box = FaceBoxHelper::toPixels(['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h, 'source' => ''], 1200, 800);
        check($box['x'] >= 0 && $box['y'] >= 0 && $box['x'] + $box['width'] <= 1200 && $box['y'] + $box['height'] <= 800, "Detected box {$x},{$y} stays inside the image.");
    }
    $padded = FaceBoxHelper::toPixels(['x' => 400, 'y' => 400, 'width' => 100, 'height' => 120, 'source' => ''], 1000, 1000);
    check($padded['x'] < 400 && $padded['width'] > 100 && $padded['height'] > 120, 'Detected boxes are padded.');

    // ---------------------------------------------------------------------
    // Replacement safety (C3) and safe upscale caps (I9)
    // ---------------------------------------------------------------------

    check(ImageHelper::planReplacement(3000, 2000, 1536, 1024) === ImageHelper::PLAN_REPLACE, 'Same ratio within 2x may replace.');
    check(ImageHelper::planReplacement(4000, 3000, 1536, 1024) === ImageHelper::PLAN_ASPECT_MISMATCH, 'Different ratio must not be cropped.');
    check(ImageHelper::planReplacement(1000, 1000, 1024, 1010) === ImageHelper::PLAN_REPLACE, 'Tiny ratio differences may be trimmed.');
    check(ImageHelper::planReplacement(6000, 4000, 1536, 1024) === ImageHelper::PLAN_TOO_SMALL, 'Much smaller output must not be upscaled over the original.');
    check(ImageHelper::planReplacement(0, 0, 100, 100) === ImageHelper::PLAN_ASPECT_MISMATCH, 'Unknown original size never replaces.');
    check(ImageHelper::getSafeEnhancementScale(800, 1600, 2400) === 1.5, 'Portrait upscale is bounded by height.');
    check(ImageHelper::getSafeEnhancementScale(600, 400, 2400) === 2.0, 'Upscale is capped at 2x.');
    check(ImageHelper::getSafeEnhancementScale(3000, 2000, 2400) === 1.0, 'Never downscale.');
    check(ImageHelper::getSafeEnhancementScale(0, 10, 2400) === 1.0, 'Invalid sizes do not scale.');
    check(ImageHelper::validateImageInfo(null) !== null, 'Unreadable images are rejected.');
    check(ImageHelper::validateImageInfo(['width' => 800, 'height' => 600, 'mime' => 'image/png'], 'image/jpg') !== null, 'Type mismatch is rejected.');
    check(ImageHelper::validateImageInfo(['width' => 800, 'height' => 600, 'mime' => 'image/jpeg'], 'image/jpg') === null, 'image/jpg is treated as image/jpeg.');
    check(ImageHelper::validateImageInfo(['width' => 8, 'height' => 600, 'mime' => 'image/jpeg']) !== null, 'Tiny images are rejected.');
    check(ImageHelper::validateImageInfo(['width' => 20000, 'height' => 600, 'mime' => 'image/jpeg']) !== null, 'Huge images are rejected.');
    check(ImageHelper::validateImageInfo(['width' => 800, 'height' => 600, 'mime' => 'image/gif']) !== null, 'Unsupported types are rejected.');

    // ---------------------------------------------------------------------
    // xAI URL allowlist, redirects and download caps (I11)
    // ---------------------------------------------------------------------

    check(HttpHelper::isAllowedXaiUrl('https://imgen.x.ai/xai-imgen/xai-tmp-imgen-1.jpeg'), 'Allow the xAI image CDN.');
    check(HttpHelper::isAllowedXaiUrl('https://x.ai/a.png'), 'Allow the apex domain.');
    check(!HttpHelper::isAllowedXaiUrl('http://imgen.x.ai/a.png'), 'Reject plain http.');
    check(!HttpHelper::isAllowedXaiUrl('https://evilx.ai/a.png'), 'Reject look-alike domains.');
    check(!HttpHelper::isAllowedXaiUrl('https://x.ai.evil.com/a.png'), 'Reject suffix tricks.');
    check(!HttpHelper::isAllowedXaiUrl('https://user:pass@imgen.x.ai/a.png'), 'Reject credentials in URLs.');
    check(!HttpHelper::isAllowedXaiUrl('https://169.254.169.254/latest'), 'Reject metadata IPs.');
    check(!HttpHelper::isAllowedXaiUrl('file:///etc/passwd'), 'Reject non-http schemes.');

    $tmp = sys_get_temp_dir() . '/image-enhancer-test-' . bin2hex(random_bytes(4));
    $fakeClient->requests = [];
    $fakeClient->handler = static function(string $method, string $url, array $options, int $call): ResponseInterface {
        if ($call === 1) {
            return new Response(302, ['Location' => 'https://storage.example.com/video.mp4?sig=1'], 'redirect body');
        }
        $options['sink']->write('final');
        return new Response(200);
    };
    HttpHelper::downloadToFile($fakeClient, 'https://generativelanguage.googleapis.com/v1beta/files/x:download', $tmp, 1000, [
        'headers' => ['x-goog-api-key' => 'secret', 'Accept' => '*/*'],
        'query' => ['alt' => 'media'],
    ], null, ['x-goog-api-key']);
    check(file_get_contents($tmp) === 'final', 'Redirect bodies do not end up in the download.');
    check($fakeClient->requests[0]['options']['allow_redirects'] === false, 'Redirects are followed manually.');
    check($fakeClient->requests[0]['options']['headers']['x-goog-api-key'] === 'secret', 'The API key is sent to the API host.');
    check(!isset($fakeClient->requests[1]['options']['headers']['x-goog-api-key']), 'The API key is not forwarded to another host.');
    check(isset($fakeClient->requests[1]['options']['headers']['Accept']), 'Non-sensitive headers are kept.');
    check(!isset($fakeClient->requests[1]['options']['query']), 'The query is not re-applied after a redirect.');
    @unlink($tmp);

    $fakeClient->requests = [];
    $fakeClient->handler = static fn(): ResponseInterface => new Response(302, ['Location' => 'https://evil.example.com/a.png']);
    $error = throws(static fn() => HttpHelper::downloadToFile($fakeClient, 'https://imgen.x.ai/a.png', $tmp, 1000, [], [HttpHelper::class, 'isAllowedXaiUrl']));
    check($error instanceof RuntimeException && count($fakeClient->requests) === 1, 'Redirects to other hosts are refused before requesting them.');
    check(!file_exists($tmp), 'Failed downloads leave no file behind.');

    $fakeClient->handler = static fn(): ResponseInterface => new Response(301, ['Location' => 'http://imgen.x.ai/a.png']);
    check(throws(static fn() => HttpHelper::downloadToFile($fakeClient, 'https://imgen.x.ai/a.png', $tmp, 1000)) instanceof RuntimeException, 'Downgrades to http are refused.');

    $fakeClient->handler = static function(string $method, string $url, array $options): ResponseInterface {
        for ($i = 0; $i < 10; $i++) {
            if ($options['sink']->write(str_repeat('x', 400)) === 0) {
                throw new RuntimeException('cURL error 23: write aborted');
            }
        }
        return new Response(200);
    };
    $error = throws(static fn() => HttpHelper::downloadToFile($fakeClient, 'https://imgen.x.ai/a.png', $tmp, 1000));
    check($error instanceof RuntimeException && str_contains($error->getMessage(), 'maximum allowed size'), 'Streams over the cap are aborted.');
    check(!file_exists($tmp), 'Oversized downloads are deleted.');

    $fakeClient->handler = static function(string $method, string $url, array $options): ResponseInterface {
        $options['on_headers'](new Response(200, ['Content-Length' => '5000']));
        return new Response(200);
    };
    check(throws(static fn() => HttpHelper::downloadToFile($fakeClient, 'https://imgen.x.ai/a.png', $tmp, 1000)) instanceof RuntimeException, 'Content-Length over the cap is rejected.');

    // ---------------------------------------------------------------------
    // Retry classification and safe error descriptions (I1, I8)
    // ---------------------------------------------------------------------

    $request = new Request('POST', 'https://hooks.slack.com/services/T000/B000/SECRET');
    $connect = new ConnectException('cURL error 28: timeout for https://hooks.slack.com/services/T000/B000/SECRET', $request);
    $rateLimited = new ClientException('429', $request, new Response(429, ['Retry-After' => '120']));
    $server = new ServerException('503', $request, new Response(503));
    $badRequest = new ClientException('400', $request, new Response(400, [], '{"error":{"message":"Your request was rejected by the safety system."}}'));
    check(HttpHelper::isRetryable($connect), 'Connection errors are retryable.');
    check(HttpHelper::isRetryable($rateLimited), '429 is retryable.');
    check(HttpHelper::isRetryable($server), '5xx is retryable.');
    check(!HttpHelper::isRetryable($badRequest), '4xx is not retryable.');
    check(!HttpHelper::isRetryable(new RuntimeException('No faces')), 'Plain exceptions are not retryable.');
    check(HttpHelper::isRetryable(new RuntimeException('wrapped', 0, $server)), 'Wrapped transient errors are retryable.');
    check(HttpHelper::getRetryAfterSeconds($rateLimited) === 120, 'Retry-After seconds are honoured.');
    check(HttpHelper::parseRetryAfter('Wed, 21 Oct 2015 07:28:00 GMT', strtotime('Wed, 21 Oct 2015 07:27:00 GMT')) === 60, 'Retry-After HTTP dates are honoured.');
    check(HttpHelper::parseRetryAfter('soon') === null && HttpHelper::getRetryAfterSeconds($connect) === null, 'Missing or invalid Retry-After yields null.');
    check(!str_contains(HttpHelper::describe($connect), 'SECRET') && !str_contains(HttpHelper::describe($rateLimited), 'SECRET'), 'Log descriptions never contain the URL.');
    check(HttpHelper::describe($rateLimited) === ClientException::class . ' (HTTP 429)', 'Log descriptions contain class and status.');
    check(!str_contains(HttpHelper::describeForUser($connect), 'SECRET'), 'User messages never contain the URL.');
    check(str_contains(HttpHelper::describeForUser($badRequest), 'safety system'), 'User messages include the provider error message.');
    check(HttpHelper::withTimeouts(300)['connect_timeout'] === HttpHelper::CONNECT_TIMEOUT && HttpHelper::withTimeouts(5)['connect_timeout'] === 5, 'Timeouts are bounded.');

    // ---------------------------------------------------------------------
    // Preview cleanup matching (I4)
    // ---------------------------------------------------------------------

    check(AssetHelper::isPreviewFilename('photo-enhancement-preview-20260928101500.jpg'), 'Match enhancement previews.');
    check(AssetHelper::isPreviewFilename('photo-face-blur-preview-20260928101500_1.png'), 'Match renamed face blur previews.');
    check(!AssetHelper::isPreviewFilename('photo-enhancement-preview-notes.jpg'), 'Require the preview timestamp.');
    check(!AssetHelper::isPreviewFilename('photo-enhanced.jpg'), 'Kept enhanced assets are not previews.');

    // ---------------------------------------------------------------------
    // Upload repair target sizes mirror Craft's file size rule (I12)
    // ---------------------------------------------------------------------

    $service = new AssetRequirementService();
    $bounds = new ReflectionMethod(AssetRequirementService::class, 'fileSizeBounds');
    $target = new ReflectionMethod(AssetRequirementService::class, 'targetDimensions');
    check($bounds->invoke($service, '<=', 1.0, 'MB') === [null, 500000], '"<= 1 MB" mirrors Craft (size <= min bytes).');
    check($bounds->invoke($service, '<', 1.0, 'MB') === [null, 499999], '"< 1 MB" mirrors Craft.');
    check($bounds->invoke($service, '>=', 2.0, 'KB') === [2499, null], '">= 2 KB" mirrors Craft (size >= max bytes).');
    check($bounds->invoke($service, '>', 2.0, 'KB') === [2500, null], '"> 2 KB" mirrors Craft.');
    check($bounds->invoke($service, '=', 1.0, 'KB') === [500, 1499], '"= 1 KB" is the rounded range.');
    check($bounds->invoke($service, '<=', 1.9, 'MB') === [null, 500000], 'Decimal values are truncated like Craft.');
    check($bounds->invoke($service, '<', 1000.0, 'B') === [null, 999], 'Byte values are exact.');
    check($bounds->invoke($service, '!=', 1.0, 'MB') === null, 'Unsupported operators are not repairable.');
    check($bounds->invoke($service, '<=', 0.0, 'MB') === null, 'Empty rule values are ignored.');

    $asset = new Asset();
    $asset->width = 4000;
    $asset->height = 3000;
    $asset->size = 2000000;
    $result = $target->invoke($service, $asset, [[
        'type' => 'fileSize', 'operator' => '<=', 'value' => 1.0, 'unit' => 'MB', 'failed' => true,
    ]]);
    $expectedScale = sqrt(500000 * 0.85 / 2000000);
    check($result['available'] && $result['width'] === (int) ceil(4000 * $expectedScale), 'Downscale target uses the Craft byte bound.');
    $result = $target->invoke($service, $asset, [[
        'type' => 'fileSize', 'operator' => '!=', 'value' => 1.0, 'unit' => 'MB', 'failed' => true,
    ]]);
    check(!$result['available'], 'Unsupported file size operators are not repairable.');
    $result = $target->invoke($service, $asset, [
        ['type' => 'width', 'operator' => '<=', 'value' => 2000.0, 'maxValue' => 0.0],
        ['type' => 'fileSize', 'operator' => '>=', 'value' => 1.0, 'unit' => 'KB', 'failed' => false],
    ]);
    check($result['available'] && $result['width'] === 2000 && $result['height'] === 1500, 'Dimension and size bounds combine.');

    echo "Passed {$checks} job helper regression checks.\n";
}
