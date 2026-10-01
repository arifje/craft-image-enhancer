<?php

declare(strict_types=1);

namespace arjanbrinkman\craftimageenhancer\helpers;

/** Bounds untrusted browser composites before an image driver decodes them. */
class EditorImageHelper
{
    public const MAX_BYTES = 25 * 1024 * 1024;
    public const MAX_PIXELS = 24000000;
    public const MAX_EDGE = 8192;

    public static function validate(?array $info, int $bytes, int $width, int $height, string $mime): ?string
    {
        if ($bytes <= 0 || $bytes > self::MAX_BYTES) {
            return 'The edited image must be smaller than 25 MB.';
        }
        if ($info === null || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)
            || $info['mime'] !== ImageHelper::normalizeMimeType($mime)) {
            return 'The edited image must use the original JPEG or PNG format.';
        }
        $w = $info['width'];
        $h = $info['height'];
        if ($w < 1 || $h < 1 || max($w, $h) > self::MAX_EDGE || $w * $h > self::MAX_PIXELS) {
            return 'The editor supports images up to 24 megapixels and 8192 pixels per edge.';
        }
        // Browsers apply EXIF orientation; portrait originals can have swapped dimensions.
        if (!(($w === $width && $h === $height) || ($w === $height && $h === $width))) {
            return 'The edited image must preserve the original dimensions.';
        }

        return null;
    }
}
