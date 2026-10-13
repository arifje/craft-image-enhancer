<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers/ImageHelper.php';
require __DIR__ . '/../src/helpers/ReformatHelper.php';

use arjanbrinkman\craftimageenhancer\helpers\ReformatHelper;

$checks = 0;
$check = static function(bool $valid, string $label) use (&$checks): void {
    if (!$valid) {
        throw new RuntimeException('Reformat geometry check failed: ' . $label);
    }
    $checks++;
};

// Target validation
$check(ReformatHelper::validateTarget(1920, 1080, 'crop') === null, '16:9 crop is valid');
$check(ReformatHelper::validateTarget(1920, 1080, 'fit') === null, '16:9 fit is valid');
$check(ReformatHelper::validateTarget(1920, 1080, 'ai') === null, '16:9 AI is valid');
$check(ReformatHelper::validateTarget(1920, 1080, 'stretch') !== null, 'unknown method is rejected');
$check(ReformatHelper::validateTarget(63, 1080, 'crop') !== null, 'edge below 64 is rejected');
$check(ReformatHelper::validateTarget(8193, 1080, 'crop') !== null, 'edge above 8192 is rejected');
$check(ReformatHelper::validateTarget(6000, 6000, 'fit') !== null, 'more than 24 MP is rejected');
$check(ReformatHelper::validateTarget(4000, 1000, 'fit') === null, '4:1 is allowed locally');
$check(ReformatHelper::validateTarget(4000, 1000, 'ai') !== null, '4:1 is rejected for AI');
$check(ReformatHelper::validateTarget(3000, 1000, 'ai') === null, '3:1 is allowed for AI');
$check(ReformatHelper::validateTarget(4100, 1000, 'crop') !== null, 'beyond 4:1 is rejected');

// Crop box: 9:16 portrait (1080x1920) to 16:9 uses the full width.
$box = ReformatHelper::cropBox(1080, 1920, 16, 9);
$check($box === ['x' => 0, 'y' => 656, 'width' => 1080, 'height' => 608], 'centred 16:9 crop of a 9:16 source');
$box = ReformatHelper::cropBox(1080, 1920, 16, 9, 0.5, 0.1);
$check($box['y'] === 0, 'focal point near the top clamps the crop to the top edge');
$box = ReformatHelper::cropBox(1080, 1920, 16, 9, 0.5, 0.9);
$check($box['y'] === 1920 - 608, 'focal point near the bottom clamps the crop to the bottom edge');
$box = ReformatHelper::cropBox(1920, 1080, 4, 5, 0.25, 0.5);
$check($box['width'] === 864 && $box['height'] === 1080 && $box['x'] === 48, 'landscape to 4:5 follows a left focal point');
$box = ReformatHelper::cropBox(1200, 800, 3, 2);
$check($box === ['x' => 0, 'y' => 0, 'width' => 1200, 'height' => 800], 'same ratio keeps everything');

// Contain rect: 9:16 in a 16:9 canvas is centred with equal side bars.
$rect = ReformatHelper::containRect(1080, 1920, 3413, 1920);
$check($rect['height'] === 1920 && $rect['width'] === 1080 && $rect['x'] === 1166 && $rect['y'] === 0, '9:16 centred in a 16:9 canvas');
$rect = ReformatHelper::containRect(1920, 1080, 1080, 1350);
$check($rect['width'] === 1080 && $rect['y'] === (1350 - $rect['height']) / 2 || $rect['y'] === intdiv(1350 - $rect['height'], 2), '16:9 centred in a 4:5 canvas');

// Default sizes
$check(ReformatHelper::defaultSize(1080, 1920, 16, 9, 'crop') === ['width' => 1080, 'height' => 608], 'crop default never upscales');
$check(ReformatHelper::defaultSize(1080, 1920, 16, 9, 'fit') === ['width' => 3413, 'height' => 1920], 'fit default contains the whole source');
$check(ReformatHelper::defaultSize(1080, 1920, 16, 9, 'ai') === ['width' => 3413, 'height' => 1920], 'AI default contains the whole source');
$size = ReformatHelper::defaultSize(6000, 8000, 16, 9, 'fit');
$check($size['width'] <= 8192 && $size['width'] * $size['height'] <= 24000000, 'large defaults stay within limits');
$check(abs($size['width'] / $size['height'] - 16 / 9) < 0.01, 'scaled default keeps the ratio');

// OpenAI output sizes
$check(ReformatHelper::openAiSize('gpt-image-2', 3413, 1920) === '2560x1440', 'gpt-image-2 gets an exact 16:9 size');
$check(ReformatHelper::openAiSize('gpt-image-2-2026-04-21', 1350, 1080) === '1344x1088', 'gpt-image-2 snapshot keeps multiples of 16');
$size = ReformatHelper::openAiSize('gpt-image-2', 1080, 1350);
[$w, $h] = array_map('intval', explode('x', $size));
$check($w % 16 === 0 && $h % 16 === 0 && $w <= 2560 && $h <= 2560, 'gpt-image-2 portrait size follows the rules');
$check(ReformatHelper::openAiSize('gpt-image-1', 3413, 1920) === '1536x1024', 'gpt-image-1 landscape uses 1536x1024');
$check(ReformatHelper::openAiSize('gpt-image-1.5', 1080, 1920) === '1024x1536', 'gpt-image-1.5 portrait uses 1024x1536');
$check(ReformatHelper::openAiSize('chatgpt-image-latest', 1000, 1000) === '1024x1024', 'square uses 1024x1024');

echo "Passed {$checks} reformat geometry checks.\n";
