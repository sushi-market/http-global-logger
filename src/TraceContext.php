<?php

namespace DF\HttpLogger;

use Closure;
use Illuminate\Support\Str;

class TraceContext
{
    private ?string $traceId = null;

    public function id(): string
    {
        return $this->traceId ??= (string) Str::uuid();
    }

    public function valid(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^[a-zA-Z0-9._-]{1,128}$/D', $id) ? $id : null;
    }

    public function run(string $traceId, Closure $callback): mixed
    {
        $previous = $this->traceId;
        $this->traceId = $traceId;

        try {
            return $callback();
        } finally {
            $this->traceId = $previous;
        }
    }
}
