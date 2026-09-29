<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

/**
 * Pure helpers for face boxes. Boxes are normalized to a 0-1000 grid so they stay valid
 * for any rendition (e.g. the downscaled copy sent for detection) of the same image.
 */
class FaceBoxHelper
{
	// Const Properties
	// =========================================================================

	public const MAX_FACES = 50;
	public const MIN_BOX_SIZE = 5;

	// Public Methods
	// =========================================================================

	/**
	 * Extracts a JSON object from a model response (raw JSON or JSON embedded in text).
	 */
	public static function extractJsonObject(string $content): ?array
	{
		$data = json_decode($content, true);
		if (is_array($data)) {
			return $data;
		}

		if (preg_match('/\{.*\}/s', $content, $matches) !== 1) {
			return null;
		}

		$data = json_decode($matches[0], true);

		return is_array($data) ? $data : null;
	}

	/**
	 * Validates and clamps `{faces: [{x, y, width, height}]}` data. At most MAX_FACES boxes.
	 *
	 * @return array<int, array{x: int, y: int, width: int, height: int, confidence: string, source: string}>
	 */
	public static function normalizeFaceBoxes(?array $data): array
	{
		if (!is_array($data) || !isset($data['faces']) || !is_array($data['faces'])) {
			return [];
		}

		$faces = [];
		foreach ($data['faces'] as $face) {
			if (count($faces) >= self::MAX_FACES) {
				break;
			}
			if (!is_array($face)) {
				continue;
			}

			$x = self::normalizeCoordinate($face['x'] ?? null);
			$y = self::normalizeCoordinate($face['y'] ?? null);
			$width = self::normalizeCoordinate($face['width'] ?? null);
			$height = self::normalizeCoordinate($face['height'] ?? null);

			if ($x === null || $y === null || $width === null || $height === null) {
				continue;
			}

			$width = min(1000 - $x, $width);
			$height = min(1000 - $y, $height);
			if ($width < self::MIN_BOX_SIZE || $height < self::MIN_BOX_SIZE) {
				continue;
			}

			$faces[] = [
				'x' => $x,
				'y' => $y,
				'width' => $width,
				'height' => $height,
				'confidence' => is_scalar($face['confidence'] ?? null) ? (string) $face['confidence'] : '',
				'source' => is_scalar($face['source'] ?? null) ? (string) $face['source'] : '',
			];
		}

		return $faces;
	}

	/**
	 * Normalizes boxes drawn by an editor and marks them as manual.
	 */
	public static function normalizeManualFaceBoxes(array $faces): array
	{
		return array_map(static fn(array $face): array => array_merge($face, [
			'confidence' => 'manual',
			'source' => 'manual',
		]), self::normalizeFaceBoxes(['faces' => $faces]));
	}

	/**
	 * Converts a normalized box to a pixel rectangle inside the image. Detected boxes are
	 * coerced to a head shape and padded; manual boxes are used as drawn.
	 *
	 * @return array{x: int, y: int, width: int, height: int}
	 */
	public static function toPixels(array $face, int $imageWidth, int $imageHeight): array
	{
		$x = (float) $face['x'] / 1000 * $imageWidth;
		$y = (float) $face['y'] / 1000 * $imageHeight;
		$width = (float) $face['width'] / 1000 * $imageWidth;
		$height = (float) $face['height'] / 1000 * $imageHeight;

		if (($face['source'] ?? '') === 'manual') {
			return self::clampRectangle($x, $y, $x + $width, $y + $height, $imageWidth, $imageHeight);
		}

		[$x, $y, $width, $height] = self::coerceToHeadShape($x, $y, $width, $height, $imageWidth, $imageHeight);
		$paddingX = $width * 0.14;
		$paddingY = $height * 0.18;

		return self::clampRectangle(
			$x - $paddingX,
			$y - $paddingY,
			$x + $width + $paddingX,
			$y + $height + $paddingY,
			$imageWidth,
			$imageHeight,
		);
	}

	// Private Methods
	// =========================================================================

	private static function normalizeCoordinate(mixed $value): ?int
	{
		if (!is_numeric($value) || !is_finite((float) $value)) {
			return null;
		}

		return max(0, min(1000, (int) round((float) $value)));
	}

	/**
	 * @return array{x: int, y: int, width: int, height: int}
	 */
	private static function clampRectangle(float $left, float $top, float $right, float $bottom, int $imageWidth, int $imageHeight): array
	{
		$x = max(0, min($imageWidth, (int) floor($left)));
		$y = max(0, min($imageHeight, (int) floor($top)));
		$right = max($x, min($imageWidth, (int) ceil($right)));
		$bottom = max($y, min($imageHeight, (int) ceil($bottom)));

		return [
			'x' => $x,
			'y' => $y,
			'width' => $right - $x,
			'height' => $bottom - $y,
		];
	}

	/**
	 * @return array{0: float, 1: float, 2: float, 3: float}
	 */
	private static function coerceToHeadShape(float $x, float $y, float $width, float $height, int $imageWidth, int $imageHeight): array
	{
		$centerX = $x + ($width / 2);
		$centerY = $y + ($height / 2);
		$ratio = $width / max(1, $height);
		$relativeArea = ($width * $height) / max(1, $imageWidth * $imageHeight);
		$bottom = $y + $height;

		if ($ratio < 0.5) {
			$width = $height * 0.68;
		} elseif ($ratio > 1.35) {
			$height = $width / 1.05;
		}

		if ($height > $width * 1.65) {
			$height = $width * 1.35;
			$centerY = $y + ($height / 2);
		}

		if ($relativeArea > 0.42 && $bottom > $imageHeight * 0.72 && $height > $width * 1.05) {
			$height = min($height, $width * 1.25);
			$centerY = $y + ($height / 2);
		}

		$width = min($width, $imageWidth);
		$height = min($height, $imageHeight);
		$x = max(0, min($centerX - ($width / 2), $imageWidth - $width));
		$y = max(0, min($centerY - ($height / 2), $imageHeight - $height));

		return [$x, $y, $width, $height];
	}
}
