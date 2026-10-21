<?php

declare(strict_types=1);

// Runs only against the disposable audit site, never the shared Craft database.
$root = '/tmp/image-enhancer-audit-20261020';
if (getenv('IE_AUDIT_SITE') !== $root . '/site') {
    throw new RuntimeException('Set IE_AUDIT_SITE to the isolated audit site.');
}
require $root . '/site/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
if ($app->getDb()->createCommand('SELECT DATABASE()')->queryScalar() !== 'image_enhancer_audit_20261020') {
    throw new RuntimeException('Wrong audit database.');
}
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\models\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

$plugin = ImageEnhancer::getInstance();
$source = (new ReflectionClass($plugin))->getFileName();
if ($source !== $root . '/plugin/src/ImageEnhancer.php') {
    throw new RuntimeException('Wrong plugin source: ' . $source);
}
$asset = new craft\elements\Asset(['filename' => 'audit.png']);
$asset->setMimeType('image/png');
$path = $root . '/provider-source.png';
$image = imagecreatetruecolor(128, 128);
imagepng($image, $path);
imagedestroy($image);
$bytes = file_get_contents($path);
$base64 = base64_encode($bytes);
$settings = new Settings([
    'chatGptApiKey' => 'audit-only-key', 'xAiApiKey' => 'audit-only-key', 'googleAiApiKey' => 'audit-only-key',
]);
$checks = 0;
foreach ([
    ['openai', ['data' => [['b64_json' => $base64]]]],
    ['xai', ['data' => [['b64_json' => $base64]]]],
    ['google', ['candidates' => [['content' => ['parts' => [['inlineData' => ['data' => $base64]]]]]]]],
    ['google', ['candidates' => 'invalid']],
    ['google', ['candidates' => [['content' => ['parts' => 'invalid']]]]],
    ['google', ['candidates' => [['content' => ['parts' => [['inlineData' => ['data' => ['invalid']]]]]]]]],
    ['google', ['candidates' => [null, 'invalid', 7]]],
    ['google', ['candidates' => [['content' => ['parts' => [['inlineData' => ['data' => '%%%']]]]]]]],
] as $index => [$provider, $payload]) {
    $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode($payload))]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $output = null;
    try {
        $output = $plugin->aiImageEnhancement->enhanceToTempFile(
            $client, $settings, $asset, $path, ['provider' => $provider, 'model' => 'audit-model'], 'Keep the image unchanged',
        );
        if ($index >= 3 || file_get_contents($output) !== $bytes) {
            throw new LogicException('Unexpected provider result.');
        }
    } catch (RuntimeException $error) {
        if ($index < 3 || !preg_match('/returned no enhanced image data|invalid base64/', $error->getMessage())) {
            throw $error;
        }
    } finally {
        if ($output !== null) unlink($output);
    }
    $checks++;
}
unlink($path);
echo "Passed {$checks} native Craft provider checks; Craft {$app->version}; source {$source}\n";
