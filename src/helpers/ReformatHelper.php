<?php

declare(strict_types=1);

namespace arjanbrinkman\craftimageenhancer\helpers;

use Craft;
use craft\image\Raster;
use Imagick;
use ImagickDraw;
use ImagickPixel;

/**
 * Changes an image's format (aspect ratio and size): crop around the focal point, fit on a
 * blurred background, or prepare and finish an AI extension (outpainting).
 *
 * Geometry methods are pure so they can be tested without Craft or Imagick.
 */
class ReformatHelper
{
    // Const Properties
    // =========================================================================

    public const METHOD_CROP = 'crop';
    public const METHOD_FIT = 'fit';
    public const METHOD_AI = 'ai';
    public const METHODS = [self::METHOD_CROP, self::METHOD_FIT, self::METHOD_AI];

    public const MIN_EDGE = 64;
    public const MAX_EDGE = 8192;
    public const MAX_PIXELS = 24000000;
    /** Local methods accept 1:4 to 4:1; AI extension follows OpenAI's 1:3 to 3:1 limit. */
    public const MAX_RATIO = 4.0;
    public const MAX_AI_RATIO = 3.0;
    public const MAX_PROMPT_LENGTH = 2000;
    /** Longest edge of the canvas sent to an AI provider. */
    public const AI_INPUT_MAX_EDGE = 2048;
    /** Longest edge requested from gpt-image-2 (larger sizes are experimental). */
    public const OPENAI_MAX_EDGE = 2560;
    public const OPENAI_MAX_PIXELS = 2560 * 1440;

    // Public Methods
    // =========================================================================

    /**
     * Returns an error message for an unusable target size, or null when it is valid.
     */
    public static function validateTarget(int $width, int $height, string $method): ?string
    {
        if (!in_array($method, self::METHODS, true)) {
            return 'Choose crop, fit or AI extend.';
        }
        if (
            $width < self::MIN_EDGE || $height < self::MIN_EDGE ||
            max($width, $height) > self::MAX_EDGE || $width * $height > self::MAX_PIXELS
        ) {
            return 'The new format must be between 64 and 8192 pixels per edge and at most 24 megapixels.';
        }

        $ratio = max($width, $height) / min($width, $height);
        $maxRatio = $method === self::METHOD_AI ? self::MAX_AI_RATIO : self::MAX_RATIO;
        if ($ratio > $maxRatio + 1e-9) {
            return $method === self::METHOD_AI
                ? 'AI extend supports aspect ratios between 1:3 and 3:1.'
                : 'The aspect ratio must be between 1:4 and 4:1.';
        }

        return null;
    }

    /**
     * Largest box with the target aspect ratio inside the source, centred on the focal point
     * (0-1 coordinates) as far as the source edges allow.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public static function cropBox(int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight, float $focalX = 0.5, float $focalY = 0.5): array
    {
        $targetRatio = $targetWidth / $targetHeight;
        if ($sourceWidth / $sourceHeight > $targetRatio) {
            $height = $sourceHeight;
            $width = max(1, min($sourceWidth, (int) round($height * $targetRatio)));
        } else {
            $width = $sourceWidth;
            $height = max(1, min($sourceHeight, (int) round($width / $targetRatio)));
        }

        $focalX = min(1.0, max(0.0, $focalX));
        $focalY = min(1.0, max(0.0, $focalY));
        $x = (int) round($focalX * $sourceWidth - $width / 2);
        $y = (int) round($focalY * $sourceHeight - $height / 2);

        return [
            'x' => min(max(0, $x), $sourceWidth - $width),
            'y' => min(max(0, $y), $sourceHeight - $height),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Where the whole source sits, centred and scaled to fit, inside the target canvas.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public static function containRect(int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight): array
    {
        $scale = min($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
        $width = max(1, min($targetWidth, (int) round($sourceWidth * $scale)));
        $height = max(1, min($targetHeight, (int) round($sourceHeight * $scale)));

        return [
            'x' => intdiv($targetWidth - $width, 2),
            'y' => intdiv($targetHeight - $height, 2),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Default output size for a ratio: crop keeps the cropped pixels (no upscaling); fit and
     * AI extend use the smallest canvas that contains the whole source, within the size limits.
     *
     * @return array{width: int, height: int}
     */
    public static function defaultSize(int $sourceWidth, int $sourceHeight, int $ratioWidth, int $ratioHeight, string $method): array
    {
        $ratio = $ratioWidth / $ratioHeight;
        if ($method === self::METHOD_CROP) {
            $box = self::cropBox($sourceWidth, $sourceHeight, $ratioWidth, $ratioHeight);
            $width = $box['width'];
            $height = $box['height'];
        } elseif ($sourceWidth / $sourceHeight > $ratio) {
            $width = $sourceWidth;
            $height = $sourceWidth / $ratio;
        } else {
            $height = $sourceHeight;
            $width = $sourceHeight * $ratio;
        }

        $scale = min(1, self::MAX_EDGE / max($width, $height), sqrt(self::MAX_PIXELS / ($width * $height)));

        return [
            'width' => max(self::MIN_EDGE, (int) floor($width * $scale)),
            'height' => max(self::MIN_EDGE, (int) floor($height * $scale)),
        ];
    }

    /**
     * The `size` sent to OpenAI's image edit endpoint. gpt-image-2 accepts any size with edges
     * divisible by 16; other GPT image models accept three fixed sizes.
     */
    public static function openAiSize(string $model, int $width, int $height): string
    {
        if (str_starts_with($model, 'gpt-image-2')) {
            $scale = min(1, self::OPENAI_MAX_EDGE / max($width, $height), sqrt(self::OPENAI_MAX_PIXELS / ($width * $height)));
            $outWidth = max(256, (int) round($width * $scale / 16) * 16);
            $outHeight = max(256, (int) round($height * $scale / 16) * 16);
            while (max($outWidth, $outHeight) > self::OPENAI_MAX_EDGE || $outWidth * $outHeight > self::OPENAI_MAX_PIXELS) {
                if ($outWidth >= $outHeight) {
                    $outWidth -= 16;
                } else {
                    $outHeight -= 16;
                }
            }

            return $outWidth . 'x' . $outHeight;
        }

        $ratio = $width / $height;
        if ($ratio > 1.2) {
            return '1536x1024';
        }

        return $ratio < 1 / 1.2 ? '1024x1536' : '1024x1024';
    }

    /**
     * Crops the source to the target ratio around the focal point and scales it to the target size.
     *
     * @throws \RuntimeException
     */
    public static function crop(string $sourcePath, string $targetPath, string $mimeType, int $width, int $height, float $focalX, float $focalY): void
    {
        if (!class_exists(Imagick::class)) {
            [$sourceWidth, $sourceHeight] = ImageHelper::getOrientedSize($sourcePath) ?? [0, 0];
            if ($sourceWidth < 1 || $sourceHeight < 1) {
                throw new \RuntimeException('Could not read the image size.');
            }
            $box = self::cropBox($sourceWidth, $sourceHeight, $width, $height, $focalX, $focalY);
            $image = Craft::$app->getImages()->loadImage($sourcePath);
            $image->crop($box['x'], $box['x'] + $box['width'], $box['y'], $box['y'] + $box['height']);
            $image->resize($width, $height);
            if ($image instanceof Raster) {
                $image->setQuality(92);
            }
            if (!$image->saveAs($targetPath)) {
                throw new \RuntimeException('Could not write the cropped image.');
            }
            self::assertWritten($targetPath, $mimeType);

            return;
        }

        $image = self::load($sourcePath);
        try {
            $box = self::cropBox($image->getImageWidth(), $image->getImageHeight(), $width, $height, $focalX, $focalY);
            $image->cropImage($box['width'], $box['height'], $box['x'], $box['y']);
            $image->setImagePage(0, 0, 0, 0);
            $image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
            self::write($image, $targetPath, $mimeType);
        } finally {
            $image->clear();
        }
    }

    /**
     * Places the whole source on a canvas of the target size, filled with a blurred, enlarged
     * copy of itself.
     *
     * @throws \RuntimeException
     */
    public static function fitWithBlur(string $sourcePath, string $targetPath, string $mimeType, int $width, int $height): void
    {
        self::requireImagick();
        $source = self::load($sourcePath);
        try {
            $canvas = self::blurredCanvas($source, $width, $height);
            try {
                $rect = self::containRect($source->getImageWidth(), $source->getImageHeight(), $width, $height);
                $foreground = clone $source;
                $foreground->resizeImage($rect['width'], $rect['height'], Imagick::FILTER_LANCZOS, 1);
                $canvas->compositeImage($foreground, Imagick::COMPOSITE_OVER, $rect['x'], $rect['y']);
                $foreground->clear();
                self::write($canvas, $targetPath, $mimeType);
            } finally {
                $canvas->clear();
            }
        } finally {
            $source->clear();
        }
    }

    /**
     * Writes the canvas and mask sent to an AI provider for outpainting. The canvas holds the
     * source in the centre with blurred placeholders around it; in the PNG mask the placeholders
     * (plus a thin seam band) are transparent, which is where the model may paint.
     *
     * @return array{width: int, height: int}
     * @throws \RuntimeException
     */
    public static function writeExtendInput(string $sourcePath, string $canvasPath, string $maskPath, string $mimeType, int $width, int $height): array
    {
        self::requireImagick();
        $scale = min(1, self::AI_INPUT_MAX_EDGE / max($width, $height));
        $canvasWidth = max(self::MIN_EDGE, (int) round($width * $scale));
        $canvasHeight = max(self::MIN_EDGE, (int) round($height * $scale));

        $source = self::load($sourcePath);
        try {
            $canvas = self::blurredCanvas($source, $canvasWidth, $canvasHeight);
            try {
                $rect = self::containRect($source->getImageWidth(), $source->getImageHeight(), $canvasWidth, $canvasHeight);
                $foreground = clone $source;
                $foreground->resizeImage($rect['width'], $rect['height'], Imagick::FILTER_LANCZOS, 1);
                $canvas->compositeImage($foreground, Imagick::COMPOSITE_OVER, $rect['x'], $rect['y']);
                $foreground->clear();
                self::write($canvas, $canvasPath, $mimeType);
            } finally {
                $canvas->clear();
            }

            $seam = max(2, (int) round(min($rect['width'], $rect['height']) * 0.01));
            $mask = new Imagick();
            try {
                $mask->newImage($canvasWidth, $canvasHeight, new ImagickPixel('transparent'), 'png');
                $mask->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
                $draw = new ImagickDraw();
                $draw->setFillColor(new ImagickPixel('white'));
                $draw->rectangle(
                    $rect['x'] > 0 ? $rect['x'] + $seam : 0,
                    $rect['y'] > 0 ? $rect['y'] + $seam : 0,
                    $rect['x'] + $rect['width'] < $canvasWidth ? $rect['x'] + $rect['width'] - 1 - $seam : $canvasWidth - 1,
                    $rect['y'] + $rect['height'] < $canvasHeight ? $rect['y'] + $rect['height'] - 1 - $seam : $canvasHeight - 1,
                );
                $mask->drawImage($draw);
                $mask->setImageFormat('png');
                if (!$mask->writeImage('png32:' . $maskPath)) {
                    throw new \RuntimeException('Could not write the outpainting mask.');
                }
            } finally {
                $mask->clear();
            }
        } finally {
            $source->clear();
        }

        return ['width' => $canvasWidth, 'height' => $canvasHeight];
    }

    /**
     * Scales the AI result to the target size and puts the full-resolution source back in the
     * centre with feathered inner edges, so the subject stays sharp and unchanged.
     *
     * @throws \RuntimeException
     */
    public static function compositeExtension(string $aiPath, string $sourcePath, string $targetPath, string $mimeType, int $width, int $height): void
    {
        self::requireImagick();
        $result = self::load($aiPath);
        try {
            $result->setImageGravity(Imagick::GRAVITY_CENTER);
            $result->cropThumbnailImage($width, $height);
            $result->setImagePage(0, 0, 0, 0);

            $source = self::load($sourcePath);
            try {
                $rect = self::containRect($source->getImageWidth(), $source->getImageHeight(), $width, $height);
                $source->resizeImage($rect['width'], $rect['height'], Imagick::FILTER_LANCZOS, 1);
                $source->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);

                $alpha = self::featherMask(
                    $rect['width'],
                    $rect['height'],
                    [
                        'left' => $rect['x'] > 0,
                        'top' => $rect['y'] > 0,
                        'right' => $rect['x'] + $rect['width'] < $width,
                        'bottom' => $rect['y'] + $rect['height'] < $height,
                    ],
                );
                try {
                    // DstIn multiplies the source's opacity by the mask's; it behaves the same on ImageMagick 6 and 7.
                    $source->compositeImage($alpha, Imagick::COMPOSITE_DSTIN, 0, 0);
                } finally {
                    $alpha->clear();
                }

                $result->compositeImage($source, Imagick::COMPOSITE_OVER, $rect['x'], $rect['y']);
            } finally {
                $source->clear();
            }

            self::write($result, $targetPath, $mimeType);
        } finally {
            $result->clear();
        }
    }

    // Private Methods
    // =========================================================================

    private static function requireImagick(): void
    {
        if (!class_exists(Imagick::class)) {
            throw new \RuntimeException('Imagick is required to change the image format this way.');
        }
    }

    private static function load(string $path): Imagick
    {
        $image = new Imagick($path);
        ImageHelper::autoOrient($image);
        $image->setImagePage(0, 0, 0, 0);

        return $image;
    }

    /**
     * A canvas of the given size filled with a heavily blurred, enlarged (cover) copy of the source.
     */
    private static function blurredCanvas(Imagick $source, int $width, int $height): Imagick
    {
        // Blurring a small copy is much faster than blurring at full size and looks the same.
        $canvas = clone $source;
        $small = max(16, (int) round(max($width, $height) / 16));
        $canvas->setImageGravity(Imagick::GRAVITY_CENTER);
        $canvas->cropThumbnailImage(
            max(1, (int) round($small * $width / max($width, $height))),
            max(1, (int) round($small * $height / max($width, $height))),
        );
        $canvas->blurImage(0, 3);
        $canvas->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1);
        $canvas->modulateImage(92, 85, 100);
        $canvas->setImagePage(0, 0, 0, 0);

        return $canvas;
    }

    /**
     * Opacity mask: opaque inside, fading to transparent over a band on the given sides.
     *
     * @param array{left: bool, top: bool, right: bool, bottom: bool} $sides
     */
    private static function featherMask(int $width, int $height, array $sides): Imagick
    {
        $feather = max(4, (int) round(min($width, $height) * 0.03));
        // Pad the mask so sides that touch the canvas edge stay fully opaque after blurring.
        $mask = new Imagick();
        $mask->newImage($width + 2 * $feather, $height + 2 * $feather, new ImagickPixel('black'), 'png');
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('white'));
        $draw->rectangle(
            $sides['left'] ? 2 * $feather : 0,
            $sides['top'] ? 2 * $feather : 0,
            $width + 2 * $feather - 1 - ($sides['right'] ? 2 * $feather : 0),
            $height + 2 * $feather - 1 - ($sides['bottom'] ? 2 * $feather : 0),
        );
        $mask->drawImage($draw);
        $mask->blurImage(0, $feather / 2);
        $mask->cropImage($width, $height, $feather, $feather);
        $mask->setImagePage(0, 0, 0, 0);
        // Turn the grayscale ramp into the mask's own alpha channel.
        $mask->setImageAlphaChannel(Imagick::ALPHACHANNEL_COPY);

        return $mask;
    }

    private static function write(Imagick $image, string $path, string $mimeType): void
    {
        $output = ImageHelper::applyOutputFormat($image, ImageHelper::normalizeMimeType($mimeType), 92);
        try {
            ImageHelper::stripMetadataKeepingIcc($output);
            if (!$output->writeImage($path)) {
                throw new \RuntimeException('Could not write the image.');
            }
        } finally {
            if ($output !== $image) {
                $output->clear();
            }
        }
        self::assertWritten($path, $mimeType);
    }

    private static function assertWritten(string $path, string $mimeType): void
    {
        if (ImageHelper::validateImageInfo(ImageHelper::getImageInfo($path), ImageHelper::normalizeMimeType($mimeType)) !== null) {
            throw new \RuntimeException('The reformatted image is not readable.');
        }
    }
}
