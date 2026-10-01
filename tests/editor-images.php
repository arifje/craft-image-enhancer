<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers/ImageHelper.php';
require __DIR__ . '/../src/helpers/EditorImageHelper.php';

use arjanbrinkman\craftimageenhancer\helpers\EditorImageHelper;

$checks = 0;
$validate = static function(?array $info, int $bytes = 1000, string $mime = 'image/png'): ?string {
    return EditorImageHelper::validate($info, $bytes, 1200, 800, $mime);
};
$info = ['width' => 1200, 'height' => 800, 'mime' => 'image/png'];
foreach ([
    $validate($info) === null,
    $validate(['width' => 800, 'height' => 1200, 'mime' => 'image/png']) === null,
    $validate(null) !== null,
    $validate($info, 0) !== null,
    $validate($info, EditorImageHelper::MAX_BYTES + 1) !== null,
    $validate($info, 1000, 'image/jpeg') !== null,
    $validate(array_merge($info, ['mime' => 'image/svg+xml'])) !== null,
    $validate(array_merge($info, ['width' => 0])) !== null,
    $validate(array_merge($info, ['width' => 1201])) !== null,
    $validate(array_merge($info, ['width' => 9000])) !== null,
    $validate(array_merge($info, ['width' => 6000, 'height' => 6000])) !== null,
    $validate(array_merge($info, ['mime' => 'image/jpeg']), 1000, 'image/jpg') === null,
] as $valid) {
    if (!$valid) throw new RuntimeException('Editor image validation failed at check ' . ($checks + 1));
    $checks++;
}
echo "Passed {$checks} editor upload validation checks.\n";
