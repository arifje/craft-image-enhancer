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
    $size = Policy::dimensions('32:18', null, null, 1080, 1920, $operation);
    $check($size['width'] * 9 === $size['height'] * 16);
}
foreach ([['16:9', 1200, 674, 'crop'], [null, 1200, null, 'pad'], ['0:9', null, null, 'crop'], ['1.5:1', null, null, 'crop'], [null, 63, 100, 'crop'], [null, 9000, 9000, 'crop'], [null, 6000, 6000, 'pad'], ['4:1', 4000, 1000, 'generative_expand'], [null, 100, 100, 'stretch']] as [$ratio, $w, $h, $operation]) {
    $reject(static fn() => Policy::dimensions($ratio, $w, $h, 1600, 900, $operation));
}
$check(Policy::relations([1, 2, 3], 4, null) === [1, 2, 3, 4]);
$check(Policy::relations([1, 2, 3], 4, 2) === [1, 4, 3]);
$check(Policy::relations([1, 2, 4], 4, null) === [1, 2, 4]);
$reject(static fn() => Policy::relations([1, 2], 4, 3));
echo "Passed $checks MCP policy checks.\n";
