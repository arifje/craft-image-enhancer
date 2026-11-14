<?php

declare(strict_types=1);

require __DIR__ . '/../src/exceptions/EnhancementException.php';
require __DIR__ . '/../src/helpers/ImageHelper.php';
require __DIR__ . '/../src/helpers/ReformatHelper.php';
require __DIR__ . '/../src/helpers/EnhancementPolicy.php';
use arjanbrinkman\craftimageenhancer\exceptions\EnhancementException;
use arjanbrinkman\craftimageenhancer\helpers\EnhancementPolicy as Policy;

$checks = 0;
$check = static function(bool $ok) use (&$checks): void {
    if (!$ok) throw new RuntimeException('MCP policy assertion failed.');
    $checks++;
};
$reject = static function(callable $call) use ($check): void {
    try { $call(); } catch (EnhancementException) { $check(true); return; }
    throw new RuntimeException('Unsafe MCP input accepted.');
};
foreach (Policy::OPERATIONS as $operation) {
    $check(Policy::dimensions('16:9', 1200, 675, 1600, 900, $operation) === ['width' => 1200, 'height' => 675]);
    // enhance keeps the picture's ratio, so its ratio-from-another-source case is tested below.
    $size = Policy::dimensions('32:18', null, null, $operation === 'enhance' ? 1920 : 1080, $operation === 'enhance' ? 1080 : 1920, $operation);
    $check($size['width'] * 9 === $size['height'] * 16);
}
// enhance: the same ratio as the picture (resize, or the whole picture), never another one.
$check(Policy::dimensions(null, null, null, 1080, 1920, 'enhance') === ['width' => 1080, 'height' => 1920]);
$check(Policy::dimensions(null, 540, 960, 1080, 1920, 'enhance') === ['width' => 540, 'height' => 960]);
$check(Policy::dimensions('9:16', null, null, 1080, 1920, 'enhance') === ['width' => 1080, 'height' => 1920]);
$check(Policy::dimensions(null, 1200, 674, 1920, 1080, 'enhance') === ['width' => 1200, 'height' => 674], 'a rounding difference of a pixel is the same ratio');
foreach ([['16:9', null, null, 1080, 1920], [null, 1200, 675, 1080, 1920], ['4:5', null, null, 1920, 1080], [null, 1080, 1350, 1920, 1080]] as [$ratio, $w, $h, $sw, $sh]) {
    $reject(static fn() => Policy::dimensions($ratio, $w, $h, $sw, $sh, 'enhance'));
}
$check(Policy::sameRatio(1200, 675, 1920, 1080, Policy::SAME_RATIO_TOLERANCE) && !Policy::sameRatio(1080, 1350, 1920, 1080, Policy::PROVIDER_RATIO_TOLERANCE));
$check(Policy::sameRatio(1024, 1536, 1080, 1620, Policy::PROVIDER_RATIO_TOLERANCE) && !Policy::sameRatio(1024, 1536, 1080, 1920, Policy::PROVIDER_RATIO_TOLERANCE), 'a provider picture in 2:3 for a 9:16 source is too far off');
$check(in_array('enhance', Policy::OPERATIONS, true) && Policy::OPERATIONS[0] === 'preserve_scene');
foreach ([['16:9', 1200, 674, 'crop'], [null, 1200, null, 'pad'], ['0:9', null, null, 'crop'], ['1.5:1', null, null, 'crop'], [null, 63, 100, 'crop'], [null, 9000, 9000, 'crop'], [null, 6000, 6000, 'pad'], ['4:1', 4000, 1000, 'generative_expand'], [null, 100, 100, 'stretch']] as [$ratio, $w, $h, $operation]) {
    $reject(static fn() => Policy::dimensions($ratio, $w, $h, 1600, 900, $operation));
}
$check(Policy::relations([1, 2, 3], 4, null) === [1, 2, 3, 4]);
$check(Policy::relations([1, 2, 3], 4, 2) === [1, 4, 3]);
$check(Policy::relations([1, 2, 4], 4, null) === [1, 2, 4]);
$reject(static fn() => Policy::relations([1, 2], 4, 3));
echo "Passed $checks MCP policy checks.\n";
