<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\StreamInterface;
use Throwable;

/** Bounded provider diagnostics for the queue and durable operation status. */
class ProviderErrorDetails
{
    /** Extract only documented error fields, never request/response dumps or exception messages. */
    public static function describe(Throwable $error, array $redactions = []): string
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if (!$current instanceof RequestException || $current->getResponse() === null) {
                continue;
            }
            $request = $current->getRequest();
            $response = $current->getResponse();
            foreach (['Authorization', 'Proxy-Authorization', 'X-Api-Key', 'X-Goog-Api-Key', 'Cookie'] as $header) {
                $value = $request->getHeaderLine($header);
                $redactions[] = $value;
                $redactions[] = preg_replace('/^(Bearer|Basic)\s+/i', '', $value);
            }
            parse_str($request->getUri()->getQuery(), $query);
            array_walk_recursive($query, static function($value) use (&$redactions): void {
                if (is_string($value)) {
                    $redactions[] = $value;
                }
            });
            $data = self::json($response->getBody());
            $errorData = is_array($data['error'] ?? null) ? $data['error'] : [];
            $message = $errorData['message'] ?? $data['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null);
            $details = [];
            foreach (['Reason' => $message, 'Code' => $errorData['code'] ?? $data['code'] ?? null, 'Type' => $errorData['type'] ?? $errorData['status'] ?? null, 'Parameter' => $errorData['param'] ?? null] as $label => $value) {
                if (is_string($value) || is_int($value)) {
                    $text = self::clean((string)$value, $redactions, $label === 'Reason' ? 600 : 100);
                    if ($text !== '') {
                        $details[] = $label . ': ' . $text;
                    }
                }
            }
            foreach (['x-request-id', 'request-id', 'x-goog-request-id'] as $header) {
                $value = $response->getHeaderLine($header);
                if ($value !== '') {
                    $details[] = 'Provider request ID: ' . self::clean($value, $redactions, 100);
                    break;
                }
            }
            return implode('; ', $details);
        }
        return '';
    }

    /** Plain text with bounded length, known credentials and common sensitive formats removed. */
    public static function clean(string $text, array $redactions = [], int $limit = 160): string
    {
        $redactions = array_filter($redactions, static fn($value): bool => is_string($value) && $value !== '');
        usort($redactions, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $text = str_replace($redactions, '[redacted]', $text);
        $text = preg_replace([
            '~(?:https?://|data:)[^\s<>"\x27]+~i',
            '~\b(?:Bearer|Basic)\s+[^\s,;"\x27]+~i',
            '~\b(?:sk-|xai-|AIza)[A-Za-z0-9_-]+~',
            '~\b(?:api[_ -]?key|access[_ -]?token|token|secret|password|authorization)\s*[:=]\s*[^\s,;]+~i',
            '~/(?:Users|home|private|tmp|var|etc)/[^\s,;]+~',
            '~[A-Za-z0-9+/=_-]{160,}~',
        ], '[redacted]', $text) ?? '';
        $text = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', strip_tags($text)) ?? '');
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }

    /** Read at most 64 KiB and preserve a seekable stream's cursor for subsequent diagnostics. */
    private static function json(StreamInterface $stream): array
    {
        if (!$stream->isSeekable()) {
            return [];
        }
        $position = null;
        try {
            $position = $stream->tell();
            $stream->rewind();
            $body = $stream->read(65537);
            $data = strlen($body) <= 65536 ? json_decode($body, true) : null;
            return is_array($data) ? $data : [];
        } catch (Throwable) {
            return [];
        } finally {
            if ($position !== null) {
                try {
                    $stream->seek($position);
                } catch (Throwable) {
                    // A broken stream must not mask the original provider failure.
                }
            }
        }
    }
}
