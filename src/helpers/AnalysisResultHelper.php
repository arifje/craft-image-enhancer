<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

/**
 * Parses and classifies the quality-check model response.
 */
class AnalysisResultHelper
{
	// Const Properties
	// =========================================================================

	public const UNKNOWN_EMOJI = '❓';
	public const UNKNOWN_LABEL = 'Onbekend';
	public const MAX_REASON_LENGTH = 1000;

	// Public Methods
	// =========================================================================

	/**
	 * Parses `{"score": 0-100, "reason": "..."}`. An invalid or out-of-range score yields
	 * `score => null` (unknown), which never triggers an enhancement.
	 *
	 * @return array{score: ?int, reason: string, valid: bool}
	 */
	public static function parse(string $content): array
	{
		$data = FaceBoxHelper::extractJsonObject($content);
		if ($data === null) {
			return [
				'score' => null,
				'reason' => self::truncate($content),
				'valid' => false,
			];
		}

		$score = self::parseScore($data['score'] ?? null);
		$reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';

		return [
			'score' => $score,
			'reason' => self::truncate($reason),
			'valid' => $score !== null,
		];
	}

	/**
	 * Returns an integer 0-100, or null for anything else (strings, booleans, decimals, out of range).
	 */
	public static function parseScore(mixed $value): ?int
	{
		if (is_int($value)) {
			$score = $value;
		} elseif (is_float($value) && is_finite($value) && floor($value) === $value) {
			$score = (int) $value;
		} elseif (is_string($value) && preg_match('/^\s*\d{1,3}\s*$/', $value) === 1) {
			$score = (int) trim($value);
		} else {
			return null;
		}

		return $score >= 0 && $score <= 100 ? $score : null;
	}

	/**
	 * @return array{emoji: string, label: string}
	 */
	public static function getScoreBand(?int $score): array
	{
		return match (true) {
			$score === null => ['emoji' => self::UNKNOWN_EMOJI, 'label' => self::UNKNOWN_LABEL],
			$score <= 39 => ['emoji' => '🔴', 'label' => 'Slecht'],
			$score <= 59 => ['emoji' => '🟠', 'label' => 'Matig'],
			$score <= 79 => ['emoji' => '🟡', 'label' => 'Goed'],
			default => ['emoji' => '🟢', 'label' => 'Uitstekend'],
		};
	}

	/**
	 * Whether a score should trigger enhancement and notifications.
	 */
	public static function isBelowThreshold(?int $score, int $threshold): bool
	{
		return $score !== null && $score <= $threshold;
	}

	// Private Methods
	// =========================================================================

	private static function truncate(string $text): string
	{
		$text = trim($text);
		if (mb_strlen($text) <= self::MAX_REASON_LENGTH) {
			return $text;
		}

		return rtrim(mb_substr($text, 0, self::MAX_REASON_LENGTH - 3)) . '...';
	}
}
