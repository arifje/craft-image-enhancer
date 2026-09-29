<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP helpers: bounded timeouts, safe error descriptions, retry classification and
 * redirect-aware downloads with host checks and a size cap.
 */
class HttpHelper
{
	// Const Properties
	// =========================================================================

	public const CONNECT_TIMEOUT = 15;
	/** Chat completions (quality check, face detection). */
	public const CHAT_TIMEOUT = 120;
	/** Image edit requests; image models can take minutes. */
	public const IMAGE_TIMEOUT = 300;
	/** Downloading a generated image. */
	public const DOWNLOAD_TIMEOUT = 120;
	/** Slack / notification requests. */
	public const NOTIFICATION_TIMEOUT = 10;
	public const MAX_IMAGE_DOWNLOAD_BYTES = 52428800;
	public const MAX_REDIRECTS = 5;

	// Public Methods
	// =========================================================================

	/**
	 * Returns Guzzle request options with bounded connect/total timeouts merged in.
	 */
	public static function withTimeouts(int $timeout, array $options = []): array
	{
		return array_merge([
			'connect_timeout' => min(self::CONNECT_TIMEOUT, $timeout),
			'timeout' => $timeout,
		], $options);
	}

	/**
	 * Returns the HTTP status code of the first response found in the exception chain.
	 */
	public static function getStatusCode(\Throwable $e): ?int
	{
		$response = self::getResponse($e);

		return $response?->getStatusCode();
	}

	/**
	 * Whether a failure is transient: connection errors/timeouts, 429 and 5xx responses.
	 */
	public static function isRetryable(\Throwable $e): bool
	{
		for ($current = $e; $current !== null; $current = $current->getPrevious()) {
			if ($current instanceof ConnectException) {
				return true;
			}

			if ($current instanceof RequestException) {
				$response = $current->getResponse();
				if ($response === null) {
					// Transfer failed mid-flight (reset, timeout while reading).
					return true;
				}

				$status = $response->getStatusCode();

				return $status === 429 || $status >= 500;
			}

			if ($current instanceof TransferException) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parses a `Retry-After` header (seconds or HTTP date) from the exception chain.
	 */
	public static function getRetryAfterSeconds(\Throwable $e, ?int $now = null): ?int
	{
		$response = self::getResponse($e);
		if ($response === null) {
			return null;
		}

		return self::parseRetryAfter($response->getHeaderLine('Retry-After'), $now);
	}

	/**
	 * Parses a `Retry-After` header value into a non-negative number of seconds.
	 */
	public static function parseRetryAfter(string $value, ?int $now = null): ?int
	{
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		if (ctype_digit($value)) {
			return (int) $value;
		}

		$timestamp = strtotime($value);
		if ($timestamp === false) {
			return null;
		}

		return max(0, $timestamp - ($now ?? time()));
	}

	/**
	 * Log-safe description: exception class and HTTP status only. Never includes the
	 * message, because Guzzle messages embed the request URL (e.g. a Slack webhook secret).
	 */
	public static function describe(\Throwable $e): string
	{
		$status = self::getStatusCode($e);

		return get_class($e) . ($status !== null ? ' (HTTP ' . $status . ')' : '');
	}

	/**
	 * User/log-facing description of a provider failure without request URLs or headers.
	 */
	public static function describeForUser(\Throwable $e): string
	{
		$response = self::getResponse($e);
		if ($response !== null) {
			$message = 'The provider returned HTTP ' . $response->getStatusCode() . '.';
			$providerMessage = self::extractProviderErrorMessage($response);

			return $providerMessage !== null ? $message . ' ' . $providerMessage : $message;
		}

		for ($current = $e; $current !== null; $current = $current->getPrevious()) {
			if ($current instanceof TransferException) {
				return 'Could not reach the provider (connection error or timeout).';
			}
		}

		return self::truncate($e->getMessage(), 500);
	}

	/**
	 * Whether a URL is an https URL on x.ai or one of its subdomains (e.g. imgen.x.ai).
	 */
	public static function isAllowedXaiUrl(string $url): bool
	{
		$parts = parse_url($url);
		if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
			return false;
		}

		$host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

		return $host === 'x.ai' || str_ends_with($host, '.x.ai');
	}

	/**
	 * Performs a request, following up to MAX_REDIRECTS redirects manually so every hop is
	 * https, passes the optional URL check, and never forwards sensitive headers to a host
	 * other than the original one.
	 *
	 * @param callable(string): bool|null $isAllowedUrl
	 * @param string[] $sensitiveHeaders Header names dropped when the host changes
	 * @param callable(): \Psr\Http\Message\StreamInterface|null $sinkFactory Creates a fresh sink per hop
	 * @throws \RuntimeException if a redirect is not allowed
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public static function requestFollowingRedirects(
		ClientInterface $client,
		string $method,
		string $url,
		array $options,
		?callable $isAllowedUrl = null,
		array $sensitiveHeaders = [],
		?callable $sinkFactory = null,
	): ResponseInterface {
		$originalHost = strtolower((string) parse_url($url, PHP_URL_HOST));
		$currentUrl = $url;
		$options['allow_redirects'] = false;

		for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
			if (strtolower((string) parse_url($currentUrl, PHP_URL_SCHEME)) !== 'https') {
				throw new \RuntimeException('Refusing to request a non-https URL.');
			}
			if ($isAllowedUrl !== null && !$isAllowedUrl($currentUrl)) {
				throw new \RuntimeException('Refusing to request a URL on an unexpected host.');
			}

			if ($sinkFactory !== null) {
				$options['sink'] = $sinkFactory();
			}

			$response = $client->request($method, $currentUrl, $options);
			$status = $response->getStatusCode();
			$location = $response->getHeaderLine('Location');
			if (!in_array($status, [301, 302, 303, 307, 308], true) || $location === '') {
				return $response;
			}

			$currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
			// The Location already carries the full query string.
			unset($options['query']);
			if ($status === 303) {
				$method = 'GET';
			}

			if (strtolower((string) parse_url($currentUrl, PHP_URL_HOST)) !== $originalHost) {
				$options['headers'] = self::withoutHeaders($options['headers'] ?? [], $sensitiveHeaders);
			}
		}

		throw new \RuntimeException('Too many redirects.');
	}

	/**
	 * Downloads a URL to a file with a byte cap, following redirects via
	 * {@see requestFollowingRedirects()}. Deletes the file on failure.
	 *
	 * @param callable(string): bool|null $isAllowedUrl
	 * @param string[] $sensitiveHeaders
	 * @throws \RuntimeException
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public static function downloadToFile(
		ClientInterface $client,
		string $url,
		string $path,
		int $maxBytes,
		array $options = [],
		?callable $isAllowedUrl = null,
		array $sensitiveHeaders = [],
	): void {
		/** @var SizeLimitedStream|null $sink */
		$sink = null;
		$options['on_headers'] = static function(ResponseInterface $response) use ($maxBytes): void {
			$length = $response->getHeaderLine('Content-Length');
			if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
				throw new \RuntimeException('The download exceeds the maximum allowed size.');
			}
		};
		// Each hop gets a truncated file so redirect bodies never end up in the download.
		$sinkFactory = static function() use (&$sink, $path, $maxBytes): SizeLimitedStream {
			$sink?->close();
			$sink = new SizeLimitedStream(Utils::streamFor(Utils::tryFopen($path, 'w+')), $maxBytes);

			return $sink;
		};

		try {
			try {
				$response = self::requestFollowingRedirects($client, 'GET', $url, $options, $isAllowedUrl, $sensitiveHeaders, $sinkFactory);
			} catch (\Throwable $e) {
				if ($sink !== null && $sink->hasExceededLimit()) {
					throw new \RuntimeException('The download exceeds the maximum allowed size.', 0, $e);
				}

				throw $e;
			}

			if ($sink === null || $sink->hasExceededLimit()) {
				throw new \RuntimeException('The download exceeds the maximum allowed size.');
			}
			if ($response->getStatusCode() >= 300) {
				throw new \RuntimeException('The download failed with HTTP ' . $response->getStatusCode() . '.');
			}

			$sink->close();
			$sink = null;
			clearstatcache(true, $path);
			$size = is_file($path) ? (int) filesize($path) : 0;
			if ($size === 0 || $size > $maxBytes) {
				throw new \RuntimeException('The download was empty or too large.');
			}
		} catch (\Throwable $e) {
			$sink?->close();
			FileHelper::delete($path);

			throw $e;
		}
	}

	// Private Methods
	// =========================================================================

	private static function getResponse(\Throwable $e): ?ResponseInterface
	{
		for ($current = $e; $current !== null; $current = $current->getPrevious()) {
			if ($current instanceof RequestException && $current->getResponse() !== null) {
				return $current->getResponse();
			}
		}

		return null;
	}

	private static function extractProviderErrorMessage(ResponseInterface $response): ?string
	{
		try {
			$body = $response->getBody();
			if ($body->isSeekable()) {
				$body->rewind();
			}
			$data = json_decode($body->read(65536), true);
		} catch (\Throwable) {
			return null;
		}

		if (!is_array($data)) {
			return null;
		}

		$message = $data['error']['message'] ?? $data['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null);
		if (!is_string($message) || trim($message) === '') {
			return null;
		}

		return self::truncate($message, 300);
	}

	/**
	 * @param array<string, mixed> $headers
	 * @param string[] $names
	 * @return array<string, mixed>
	 */
	private static function withoutHeaders(array $headers, array $names): array
	{
		$names = array_map('strtolower', $names);

		return array_filter(
			$headers,
			static fn(string $name): bool => !in_array(strtolower($name), $names, true),
			ARRAY_FILTER_USE_KEY,
		);
	}

	private static function truncate(string $text, int $limit): string
	{
		$text = trim($text);

		return strlen($text) > $limit ? rtrim(substr($text, 0, $limit - 3)) . '...' : $text;
	}
}
