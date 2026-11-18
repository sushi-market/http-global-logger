<?php

namespace DF\HttpLogger;

use Illuminate\Contracts\Config\Repository;
use Psr\Http\Message\StreamInterface;

class HttpPayload
{
    public function __construct(private Repository $config, private PayloadSanitizer $sanitizer) {}

    public function data(array $data, bool $headers = false): mixed
    {
        return $this->sanitizer->limit(
            $this->sanitizer->redact($data, $this->config->get(
                $headers ? 'http-logger.redacted_headers' : 'http-logger.redacted_fields', []
            )),
            $this->limit(),
        );
    }

    public function limit(): int
    {
        return max(0, (int) $this->config->get('http-logger.max_body_bytes', 16384));
    }

    public function stream(StreamInterface $stream, string $contentType, bool $skip = false): mixed
    {
        if ($skip || !$stream->isReadable() || !$stream->isSeekable()) {
            return ['omitted' => 'stream_or_file'];
        }

        if (
            !str_contains(strtolower($contentType), 'json')
            && !str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')
        ) {
            return ['omitted' => 'unstructured_body'];
        }

        $size = $stream->getSize();
        if ($size !== null && $size > $this->limit()) {
            return ['omitted' => 'size_limit'];
        }

        $position = $stream->tell();
        try {
            $stream->rewind();
            $body = '';

            while (!$stream->eof() && strlen($body) <= $this->limit()) {
                $chunk = $stream->read(min(8192, $this->limit() + 1 - strlen($body)));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }

            if (strlen($body) > $this->limit()) {
                return ['omitted' => 'size_limit'];
            }

            if (str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
                parse_str($body, $decoded);
            } else {
                $decoded = json_decode($body, true);
            }

            return is_array($decoded) ? $this->data($decoded) : ['omitted' => 'unstructured_body'];
        } finally {
            $stream->seek($position);
        }
    }
}
