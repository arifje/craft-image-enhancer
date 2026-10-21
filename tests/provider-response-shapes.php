<?php

declare(strict_types=1);

// No network or application: exercise the production response parser directly.
namespace ImageEnhancerResponseDoubles\craft\base {
    class Component {}
}

namespace {
    // Process-local framework boundaries; analysis continues to see the real Craft types.
    foreach ([
        'craft\base\Component' => \ImageEnhancerResponseDoubles\craft\base\Component::class,
    ] as $contract => $double) {
        class_alias($double, $contract);
    }

    require __DIR__ . '/../src/services/AiImageEnhancementService.php';
    require __DIR__ . '/../src/helpers/JobStatus.php';
    require __DIR__ . '/../src/services/AiVideoGenerationService.php';

    use arjanbrinkman\craftimageenhancer\services\AiImageEnhancementService;

    set_error_handler(static function(int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });
    $service = new AiImageEnhancementService();
    $parse = new ReflectionMethod($service, 'extractGoogleImageData');
    $checks = 0;
    foreach ([
        null,
        [],
        ['candidates' => 'invalid'],
        ['candidates' => [null, 'invalid', 7]],
        ['candidates' => [['content' => ['parts' => 'invalid']]]],
        ['candidates' => [['content' => ['parts' => [null, 'invalid', ['inlineData' => ['data' => ['invalid']]]]]]]],
        ['candidates' => [['content' => ['parts' => [['inlineData' => ['data' => 123]]]]]]],
    ] as $response) {
        if ($parse->invoke($service, $response) !== null) {
            throw new RuntimeException('Malformed response must yield no image.');
        }
        $checks++;
    }
    foreach (['inline_data', 'inlineData'] as $key) {
        $response = ['candidates' => [['content' => ['parts' => [
            ['text' => 'ignored'], [$key => ['data' => 'aW1hZ2U=']],
        ]]]]];
        if ($parse->invoke($service, $response) !== 'aW1hZ2U=') {
            throw new RuntimeException('Valid inline image must survive.');
        }
        $checks++;
    }
    $video = new \arjanbrinkman\craftimageenhancer\services\AiVideoGenerationService();
    $videoParse = new ReflectionMethod($video, 'extractGoogleVideoOutput');
    foreach ([[], ['steps' => 'invalid'], ['steps' => [null, 'invalid']], ['steps' => [['content' => 'invalid']]], ['steps' => [['content' => [null, 'invalid', 7]]]]] as $response) {
        if ($videoParse->invoke($video, $response) !== []) throw new RuntimeException('Malformed video response must yield no output.');
        $checks++;
    }
    $valid = ['type' => 'video', 'data' => 'base64'];
    if ($videoParse->invoke($video, ['steps' => [['content' => [$valid]]]]) !== $valid) throw new RuntimeException('Video content must survive.');
    $checks++;
    restore_error_handler();
    echo "Passed {$checks} provider response shape checks.\n";
}
