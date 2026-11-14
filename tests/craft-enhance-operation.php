<?php

declare(strict_types=1);

// The `enhance` operation of image_enhance: the site's own enhancement prompt, the picture's own ratio.
// Run on the shared Craft 5 test site (Image Enhancer, MCP Tools and the AI Assistant linked there),
// with a stand-in for the provider's HTTP API, so nothing is sent anywhere and nothing is paid:
//   bin/run-php tests/craft-enhance-operation.php
use arifje\craftaiassistant\Plugin as Assistant;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\migrations\m261031_120000_add_mcp_requests as Requests;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\Json;

$autoload = $argv[1] ?? '';
require $autoload;
define('CRAFT_BASE_PATH', dirname($autoload, 2));
define('CRAFT_VENDOR_PATH', dirname($autoload));
if (class_exists(Dotenv\Dotenv::class) && is_file(CRAFT_BASE_PATH . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(CRAFT_BASE_PATH)->safeLoad();
}
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$failures = 0;
$check = static function(string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($detail !== '' && !$ok ? '  (' . $detail . ')' : '') . PHP_EOL;
    $failures += $ok ? 0 : 1;
};

$plugin = ImageEnhancer::getInstance();
$assistant = Assistant::getInstance();
$check('the plugins are installed', $plugin !== null && $assistant !== null);
$admin = User::find()->admin()->status(null)->one();
$app->getUser()->setIdentity($admin);
$volume = $app->getVolumes()->getAllVolumes()[0] ?? null;
if ($volume === null) {
    throw new RuntimeException('The test site has no asset volume.');
}

// A portrait picture (9:16), as a frame of a phone video is.
$suffix = bin2hex(random_bytes(3));
$image = static function(int $w, int $h): string {
    $im = new Imagick();
    $im->newImage($w, $h, new ImagickPixel('#6584aa'));
    $draw = new ImagickDraw();
    $draw->setFillColor('#d33');
    $draw->circle((int)($w / 2), (int)($h / 2), (int)($w / 2), (int)($h / 2 + $w / 3));
    $im->drawImage($draw);
    $im->setImageFormat('png');

    return (string)$im->getImageBlob();
};
$path = sys_get_temp_dir() . "/enhance-op-$suffix.png";
file_put_contents($path, $image(1080, 1920));
$source = new Asset(['tempFilePath' => $path, 'filename' => "enhance-op-$suffix.png", 'newFolderId' => $app->getAssets()->getRootFolderByVolumeId($volume->id)->id, 'volumeId' => $volume->id, 'title' => 'Enhance op test']);
$source->setScenario(Asset::SCENARIO_CREATE);
if (!ImageEnhancer::suppressAssetQueue(static fn(): bool => $app->getElements()->saveElement($source))) {
    throw new RuntimeException('Could not save the source: ' . Json::encode($source->getErrors()));
}

// The provider's HTTP API, stood in for. Its answer is a picture of the size the test asks for.
$sent = [];
$answer = ['w' => 1080, 'h' => 1920];
Craft::$container->set(GuzzleHttp\Client::class, static function($container, $params) use (&$sent, &$answer, $image) {
    $config = $params[0] ?? [];
    $config['handler'] = static function($request) use (&$sent, &$answer, $image) {
        if ($request->getMethod() === 'GET') {
            return GuzzleHttp\Promise\Create::promiseFor(new GuzzleHttp\Psr7\Response(200, [], Json::encode(['data' => [['id' => 'gpt-image-2']]])));
        }
        $sent[] = (string)$request->getBody();

        return GuzzleHttp\Promise\Create::promiseFor(new GuzzleHttp\Psr7\Response(200, [], Json::encode(['data' => [['b64_json' => base64_encode($image($answer['w'], $answer['h']))]]])));
    };

    return new GuzzleHttp\Client($config);
});
$settings = $plugin->getSettings();
$settings->chatGptApiKey = 'TEST_KEY_NEVER_SEND';
$settings->imageEnhancementProvider = 'openai';
$settings->imageEnhancementModel = 'gpt-image-2';

$tools = $assistant->getTools();
$requestIds = [];
$made = [];
$run = static function(array $arguments) use ($tools, $admin, $source, $suffix, $app, &$requestIds, &$made): array {
    static $n = 0;
    $n++;
    $call = $tools->call($admin, 'image_enhance', $arguments + ['sourceAssetId' => (int)$source->id, 'idempotencyKey' => "enhance-op-$suffix-$n"]);
    if (!$call['ok']) {
        return ['refused' => $call['output']];
    }
    $requestIds[] = $call['data']['operationId'];
    $app->getQueue()->executeJob((string)$call['data']['queueJobId']);
    $status = $tools->call($admin, 'image_enhancement_status', ['operationId' => $call['data']['operationId']]);
    if (isset($status['data']['result']['assetId'])) {
        $made[] = (int)$status['data']['result']['assetId'];
    }

    return $status['data'] ?? ['refused' => $status['output']];
};

// Discovery.
$options = $tools->call($admin, 'image_enhancement_options', [])['data'] ?? [];
$operations = array_column($options['operations'] ?? [], null, 'operation');
$check('discovery lists enhance, available and with AI cost', ($operations['enhance']['available'] ?? false) === true && ($operations['enhance']['incursAiCosts'] ?? false) === true, Json::encode($operations['enhance'] ?? null));
$check('the provider offers enhance', in_array('enhance', $options['providers'][0]['operations'] ?? [], true), Json::encode($options['providers'][0] ?? null));
$definition = array_column($tools->definitions($admin), null, 'name')['image_enhance']['parameters']['properties']['operation']['enum'] ?? [];
$check('the tool schema advertises enhance', in_array('enhance', $definition, true), Json::encode($definition));

// enhance: the site's prompt, the picture's own size.
$result = $run(['operation' => 'enhance', 'provider' => 'openai', 'model' => 'gpt-image-2']);
$check('enhance completes', ($result['stage'] ?? '') === 'complete', Json::encode($result));
$check('the result keeps the size and ratio of the picture: no padding, no crop', ($result['result']['width'] ?? 0) === 1080 && ($result['result']['height'] ?? 0) === 1920);
$check('the provider got the site\'s enhancement prompt and tuning levels', count($sent) === 1 && str_contains($sent[0], 'ultra-clear') && str_contains($sent[0], 'Enhancement tuning levels'));
$check('and not the preservation prompt', !str_contains($sent[0] ?? '', 'Correct exposure, colour balance and mild noise only'));
$check('the provenance says which prompt', ($result['provenance']['enhancementPrompt'] ?? null) === 'site-default' && ($result['provenance']['operation'] ?? null) === 'enhance' && ($result['provenance']['aiProcessed'] ?? false) === true);

// Extra direction adds to the prompt.
$sent = [];
$result = $run(['operation' => 'enhance', 'provider' => 'openai', 'model' => 'gpt-image-2', 'instructions' => 'Make the red disc edge crisper.']);
$check('extra direction is added after the site\'s prompt', ($result['stage'] ?? '') === 'complete' && str_contains($sent[0] ?? '', 'Additional direction') && str_contains($sent[0], 'Make the red disc edge crisper.') && str_contains($sent[0], 'ultra-clear'), Json::encode($result));

// A smaller size in the same ratio is a resize after the enhancement.
$sent = [];
$result = $run(['operation' => 'enhance', 'provider' => 'openai', 'model' => 'gpt-image-2', 'width' => 540, 'height' => 960]);
$check('the same ratio at another size is allowed', ($result['stage'] ?? '') === 'complete' && ($result['result']['width'] ?? 0) === 540 && ($result['result']['height'] ?? 0) === 960, Json::encode($result));

// Another ratio is refused before anything is paid.
$sent = [];
$result = $run(['operation' => 'enhance', 'provider' => 'openai', 'model' => 'gpt-image-2', 'width' => 1200, 'height' => 675]);
$check('another ratio is refused, with what to do instead', str_contains((string)($result['refused'] ?? ''), 'crop or generative_expand') && $sent === [], Json::encode($result));
$result = $run(['operation' => 'enhance', 'provider' => 'openai', 'model' => 'gpt-image-2', 'aspectRatio' => '16:9']);
$check('also when asked as a ratio', isset($result['refused']) && $sent === [], Json::encode($result));

// A provider that sends the picture back in another ratio does not get it cropped.
$answer = ['w' => 1024, 'h' => 1536];
$sent = [];
$result = $run(['operation' => 'enhance', 'provider' => 'openai', 'model' => 'gpt-image-2']);
$check('a picture in another ratio from the provider fails instead of cutting it', ($result['stage'] ?? '') === 'failed' && str_contains((string)($result['error'] ?? ''), 'another ratio') && ($result['result'] ?? null) === null, Json::encode($result));
$answer = ['w' => 1080, 'h' => 1920];

// The existing operations are as they were.
$sent = [];
$result = $run(['operation' => 'preserve_scene', 'provider' => 'openai', 'model' => 'gpt-image-2']);
$check('preserve_scene still uses the preservation prompt', ($result['stage'] ?? '') === 'complete' && str_contains($sent[0] ?? '', 'Correct exposure, colour balance and mild noise only') && !str_contains($sent[0], 'ultra-clear') && ($result['provenance']['enhancementPrompt'] ?? null) === 'preservation', Json::encode($result));
$sent = [];
$result = $run(['operation' => 'crop', 'width' => 1200, 'height' => 675]);
$check('crop is local and still works', ($result['stage'] ?? '') === 'complete' && $sent === [] && ($result['result']['width'] ?? 0) === 1200, Json::encode($result));

// Clean up what this script made.
foreach (array_unique([...$made, (int)$source->id]) as $id) {
    if (($asset = Asset::find()->id($id)->status(null)->one()) !== null) {
        $app->getElements()->deleteElement($asset, true);
    }
}
$app->getDb()->createCommand()->delete(Requests::TABLE, ['requestId' => $requestIds])->execute();
@unlink($path);

echo PHP_EOL . ($failures === 0 ? 'All checks passed.' : "{$failures} check(s) failed.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
