<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

use Craft;
use craft\image\Raster;
use Imagick;
use ImagickPixel;

/**
 * Image inspection, validation and rewriting helpers (Imagick with a Craft image fallback).
 */
class ImageHelper
{
	// Const Properties
	// =========================================================================

	/** Longest edge of the copy sent to the model for quality checks and face detection. */
	public const ANALYSIS_MAX_DIMENSION = 2048;
	public const ANALYSIS_JPEG_QUALITY = 85;
	/** Maximum relative aspect-ratio difference that may be cropped away when replacing. */
	public const MAX_ASPECT_DEVIATION = 0.03;
	/** Maximum upscale factor applied to provider output to restore the original size. */
	public const MAX_REPLACEMENT_UPSCALE = 2.0;
	/** Maximum upscale factor of the safe (local) enhancement. */
	public const SAFE_MAX_UPSCALE = 2.0;
	public const MIN_DIMENSION = 16;
	public const MAX_DIMENSION = 16384;

	public const PLAN_REPLACE = 'replace';
	public const PLAN_ASPECT_MISMATCH = 'aspect-mismatch';
	public const PLAN_TOO_SMALL = 'too-small';

	// Public Methods
	// =========================================================================

	public static function normalizeMimeType(?string $mimeType): string
	{
		$mimeType = strtolower(trim((string) $mimeType));

		return match ($mimeType) {
			'image/jpg', 'image/pjpeg' => 'image/jpeg',
			'image/x-png' => 'image/png',
			default => $mimeType,
		};
	}

	/**
	 * Returns width, height and the detected MIME type of an image file, or null.
	 *
	 * @return array{width: int, height: int, mime: string}|null
	 */
	public static function getImageInfo(string $path): ?array
	{
		if (!is_file($path)) {
			return null;
		}

		$size = @getimagesize($path);
		if ($size === false) {
			return null;
		}

		return [
			'width' => (int) $size[0],
			'height' => (int) $size[1],
			'mime' => self::normalizeMimeType($size['mime']),
		];
	}

	/**
	 * Returns the display size of an image, i.e. with EXIF orientation applied.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public static function getOrientedSize(string $path): ?array
	{
		$info = self::getImageInfo($path);
		if ($info === null) {
			return null;
		}

		if (class_exists(Imagick::class)) {
			try {
				$image = new Imagick();
				$image->pingImage($path);
				$orientation = $image->getImageOrientation();
				$image->clear();
				if (in_array($orientation, [5, 6, 7, 8], true)) {
					return [$info['height'], $info['width']];
				}
			} catch (\Throwable) {
				// Fall back to the stored size.
			}
		}

		return [$info['width'], $info['height']];
	}

	/**
	 * Returns null when the image info is acceptable, otherwise the reason it is not.
	 *
	 * @param array{width: int, height: int, mime: string}|null $info
	 */
	public static function validateImageInfo(?array $info, ?string $expectedMimeType = null): ?string
	{
		if ($info === null) {
			return 'the file is not a readable image';
		}
		if (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
			return 'unsupported image type ' . ($info['mime'] !== '' ? $info['mime'] : 'unknown');
		}
		if ($expectedMimeType !== null && $info['mime'] !== self::normalizeMimeType($expectedMimeType)) {
			return 'image type ' . $info['mime'] . ' does not match ' . self::normalizeMimeType($expectedMimeType);
		}
		if ($info['width'] < self::MIN_DIMENSION || $info['height'] < self::MIN_DIMENSION) {
			return 'image is too small (' . $info['width'] . 'x' . $info['height'] . ')';
		}
		if ($info['width'] > self::MAX_DIMENSION || $info['height'] > self::MAX_DIMENSION) {
			return 'image is too large (' . $info['width'] . 'x' . $info['height'] . ')';
		}

		return null;
	}

	/**
	 * Decides whether provider output may replace an original of the given size.
	 *
	 * - PLAN_REPLACE: aspect ratio matches (within MAX_ASPECT_DEVIATION) and restoring the
	 *   original size needs at most MAX_REPLACEMENT_UPSCALE; only a sliver is cropped.
	 * - PLAN_ASPECT_MISMATCH: cropping to the original ratio would cut visible edges.
	 * - PLAN_TOO_SMALL: the output is much smaller; upscaling it would degrade the original.
	 */
	public static function planReplacement(int $originalWidth, int $originalHeight, int $outputWidth, int $outputHeight): string
	{
		if ($originalWidth <= 0 || $originalHeight <= 0 || $outputWidth <= 0 || $outputHeight <= 0) {
			return self::PLAN_ASPECT_MISMATCH;
		}

		$originalRatio = $originalWidth / $originalHeight;
		$outputRatio = $outputWidth / $outputHeight;
		if (abs($outputRatio - $originalRatio) / $originalRatio > self::MAX_ASPECT_DEVIATION) {
			return self::PLAN_ASPECT_MISMATCH;
		}

		$upscale = max($originalWidth / $outputWidth, $originalHeight / $outputHeight);
		if ($upscale > self::MAX_REPLACEMENT_UPSCALE) {
			return self::PLAN_TOO_SMALL;
		}

		return self::PLAN_REPLACE;
	}

	/**
	 * Scale factor for the safe enhancement: grow towards `$maxWidth` inside a
	 * `$maxWidth` x `$maxWidth` box, never more than SAFE_MAX_UPSCALE, never shrink.
	 */
	public static function getSafeEnhancementScale(int $width, int $height, int $maxWidth): float
	{
		if ($width <= 0 || $height <= 0) {
			return 1.0;
		}

		$bound = max(1, $maxWidth);
		$scale = min($bound / $width, $bound / $height, self::SAFE_MAX_UPSCALE);

		return $scale > 1.0 ? $scale : 1.0;
	}

	/**
	 * Applies EXIF orientation to the pixels and resets the orientation tag.
	 */
	public static function autoOrient(Imagick $image): void
	{
		if (method_exists($image, 'autoOrient')) {
			$image->autoOrient();

			return;
		}

		$background = new ImagickPixel('#000');
		match ($image->getImageOrientation()) {
			Imagick::ORIENTATION_TOPRIGHT => $image->flopImage(),
			Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage($background, 180),
			Imagick::ORIENTATION_BOTTOMLEFT => $image->flipImage(),
			Imagick::ORIENTATION_LEFTTOP => $image->transposeImage(),
			Imagick::ORIENTATION_RIGHTTOP => $image->rotateImage($background, 90),
			Imagick::ORIENTATION_RIGHTBOTTOM => $image->transverseImage(),
			Imagick::ORIENTATION_LEFTBOTTOM => $image->rotateImage($background, 270),
			default => null,
		};
		$image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
	}

	/**
	 * Removes metadata (EXIF, XMP, comments) but keeps the ICC color profile.
	 */
	public static function stripMetadataKeepingIcc(Imagick $image): void
	{
		$profiles = $image->getImageProfiles('icc', true);
		$image->stripImage();
		if (!empty($profiles['icc'])) {
			$image->profileImage('icc', $profiles['icc']);
		}
	}

	/**
	 * Sets the output format for the MIME type. JPEG output flattens transparency onto white.
	 * Returns the image to write, which may be a new instance; the caller clears both.
	 */
	public static function applyOutputFormat(Imagick $image, string $mimeType, int $jpegQuality = 90): Imagick
	{
		$mimeType = self::normalizeMimeType($mimeType);
		if ($mimeType === 'image/png') {
			$image->setImageFormat('png');

			return $image;
		}

		if ($mimeType !== 'image/jpeg') {
			return $image;
		}

		if ($image->getImageAlphaChannel()) {
			$image->setImageBackgroundColor(new ImagickPixel('white'));
			$image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
		}

		$image->setImageFormat('jpeg');
		$image->setImageCompression(Imagick::COMPRESSION_JPEG);
		$image->setImageCompressionQuality(min(100, max(1, $jpegQuality)));

		return $image;
	}

	/**
	 * Writes a downscaled (max ANALYSIS_MAX_DIMENSION), auto-oriented JPEG copy for sending
	 * to a vision model. The caller owns and deletes the returned file.
	 *
	 * @throws \RuntimeException
	 */
	public static function createAnalysisCopy(string $sourcePath, int $maxDimension = self::ANALYSIS_MAX_DIMENSION): string
	{
		$targetPath = FileHelper::createTempPath('jpg');

		try {
			if (class_exists(Imagick::class)) {
				$image = new Imagick($sourcePath);
				$output = $image;
				try {
					self::autoOrient($image);
					if ($image->getImageWidth() > $maxDimension || $image->getImageHeight() > $maxDimension) {
						$image->resizeImage($maxDimension, $maxDimension, Imagick::FILTER_LANCZOS, 1, true);
					}
					$output = self::applyOutputFormat($image, 'image/jpeg', self::ANALYSIS_JPEG_QUALITY);
					self::stripMetadataKeepingIcc($output);
					if (!$output->writeImage($targetPath)) {
						throw new \RuntimeException('Could not write the analysis copy.');
					}
				} finally {
					if ($output !== $image) {
						$output->clear();
					}
					$image->clear();
				}
			} else {
				$image = Craft::$app->getImages()->loadImage($sourcePath);
				$image->scaleToFit($maxDimension, $maxDimension, false);
				if ($image instanceof Raster) {
					$image->setQuality(self::ANALYSIS_JPEG_QUALITY);
				}
				if (!$image->saveAs($targetPath)) {
					throw new \RuntimeException('Could not write the analysis copy.');
				}
			}

			if (self::getImageInfo($targetPath) === null) {
				throw new \RuntimeException('The analysis copy is not a readable image.');
			}
		} catch (\Throwable $e) {
			FileHelper::delete($targetPath);

			throw new \RuntimeException('Could not prepare the image for analysis.', 0, $e);
		}

		return $targetPath;
	}

	/**
	 * Scales and center-crops the image in place to exactly the given size, in the given format.
	 *
	 * @throws \RuntimeException
	 */
	public static function cropToSize(string $path, string $mimeType, int $width, int $height, int $jpegQuality = 90): void
	{
		self::rewrite(
			$path,
			$mimeType,
			$jpegQuality,
			static function(Imagick $image) use ($width, $height): void {
				$image->setImageGravity(Imagick::GRAVITY_CENTER);
				$image->cropThumbnailImage($width, $height);
				$image->setImagePage(0, 0, 0, 0);
			},
			static fn(\craft\base\Image $image) => $image->scaleAndCrop($width, $height, true),
		);
	}

	/**
	 * Downscales the image in place to fit inside the given box (never upscales, never
	 * crops) and converts it to the given format.
	 *
	 * @throws \RuntimeException
	 */
	public static function fitWithin(string $path, string $mimeType, int $maxWidth, int $maxHeight, int $jpegQuality = 90): void
	{
		self::rewrite(
			$path,
			$mimeType,
			$jpegQuality,
			static function(Imagick $image) use ($maxWidth, $maxHeight): void {
				if ($image->getImageWidth() > $maxWidth || $image->getImageHeight() > $maxHeight) {
					$image->resizeImage($maxWidth, $maxHeight, Imagick::FILTER_LANCZOS, 1, true);
				}
			},
			static fn(\craft\base\Image $image) => $image->scaleToFit($maxWidth, $maxHeight, false),
		);
	}

	// Private Methods
	// =========================================================================

	/**
	 * Rewrites an image in place via a temp file, so a failed write never leaves a
	 * half-written file behind.
	 *
	 * @param callable(Imagick): void $imagickOperation
	 * @param callable(\craft\base\Image): mixed $craftOperation
	 * @throws \RuntimeException
	 */
	private static function rewrite(string $path, string $mimeType, int $jpegQuality, callable $imagickOperation, callable $craftOperation): void
	{
		$mimeType = self::normalizeMimeType($mimeType);
		$extension = $mimeType === 'image/png' ? 'png' : 'jpg';
		$targetPath = FileHelper::createTempPath($extension);

		try {
			if (class_exists(Imagick::class)) {
				$image = new Imagick($path);
				$output = $image;
				try {
					self::autoOrient($image);
					$imagickOperation($image);
					$output = self::applyOutputFormat($image, $mimeType, $jpegQuality);
					if (!$output->writeImage($targetPath)) {
						throw new \RuntimeException('Could not write the image.');
					}
				} finally {
					if ($output !== $image) {
						$output->clear();
					}
					$image->clear();
				}
			} else {
				$image = Craft::$app->getImages()->loadImage($path);
				$craftOperation($image);
				if ($image instanceof Raster) {
					$image->setQuality($jpegQuality);
				}
				if (!$image->saveAs($targetPath)) {
					throw new \RuntimeException('Could not write the image.');
				}
			}

			if (self::validateImageInfo(self::getImageInfo($targetPath), $mimeType) !== null || !@rename($targetPath, $path)) {
				throw new \RuntimeException('Could not write the image.');
			}
		} catch (\Throwable $e) {
			throw new \RuntimeException('Could not normalize the image.', 0, $e);
		} finally {
			FileHelper::delete($targetPath);
		}
	}
}
