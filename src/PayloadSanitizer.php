<?php

declare(strict_types=1);

namespace DF\HttpLogger;

class PayloadSanitizer
{
    public function redact(array $data, array $keys): array
    {
        $keys = array_map('strtolower', $keys);
        foreach ($data as $key => $value) {
            $data[$key] = in_array(strtolower((string) $key), $keys, true)
                ? '[REDACTED]'
                : (is_array($value) ? $this->redact($value, $keys) : $value);
        }

        return $data;
    }

    public function limit(mixed $data, int $bytes): mixed
    {
        $encoded = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false || strlen($encoded) > max(0, $bytes)
            ? ['omitted' => 'size_limit']
            : $data;
    }
}
