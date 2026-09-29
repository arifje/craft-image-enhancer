<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Download sink that refuses to write more than a fixed number of bytes.
 *
 * Returning a short write makes cURL abort the transfer (CURLE_WRITE_ERROR), and makes
 * Guzzle's stream handler stop copying, so an oversized response never fills the disk.
 */
class SizeLimitedStream implements StreamInterface
{
	// Traits
	// =========================================================================

	use StreamDecoratorTrait;

	// Private Properties
	// =========================================================================

	/**
	 * @var StreamInterface The decorated stream
	 */
	private StreamInterface $stream;

	private int $_maxBytes;
	private int $_written = 0;
	private bool $_exceeded = false;

	// Public Methods
	// =========================================================================

	public function __construct(StreamInterface $stream, int $maxBytes)
	{
		$this->stream = $stream;
		$this->_maxBytes = $maxBytes;
	}

	/**
	 * @param string $string
	 */
	public function write($string): int
	{
		$length = strlen($string);
		if ($this->_exceeded || $this->_written + $length > $this->_maxBytes) {
			$this->_exceeded = true;

			return 0;
		}

		$written = $this->stream->write($string);
		$this->_written += $written;

		return $written;
	}

	public function hasExceededLimit(): bool
	{
		return $this->_exceeded;
	}
}
