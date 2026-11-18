<?php

namespace DF\HttpLogger\Middleware;

use Closure;
use DF\HttpLogger\PayloadSanitizer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LogHttpRequest
{
    private const STATE = '_http_logger';

    public function __construct(
        private Repository $config,
        private Application $app,
        private PayloadSanitizer $sanitizer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (
            $this->config->get('http-logger.enabled', true)
            && !$request->attributes->has(self::STATE)
            && !$request->is(...$this->config->get('http-logger.except', []))
        ) {
            try {
                $request->attributes->set(self::STATE, [
                    'start' => hrtime(true),
                    'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'request' => [
                        'method' => $request->method(),
                        'path' => $request->getPathInfo(),
                        'query' => $this->payload($request->query()),
                        'body' => $this->requestBody($request),
                        'headers' => $this->headers($request->headers->all()),
                    ],
                ]);
            } catch (Throwable $exception) {
                error_log('HTTP logger capture failed: '.get_class($exception));
            }
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $state = $request->attributes->get(self::STATE);
        if (! is_array($state)) {
            return;
        }
        $request->attributes->remove(self::STATE);

        try {
            $duration = round((hrtime(true) - $state['start']) / 1_000_000, 3);
            $user = $request->user();
            $channel = $this->config->get('http-logger.channel');
            $logger = $channel === null
                ? $this->app->make(LoggerInterface::class)
                : $this->app->make('log')->channel($channel);

            $logger->log($this->config->get('http-logger.level', 'info'), 'http.request', [
                'started_at' => $state['started_at'],
                'duration_ms' => $duration,
                'initiator' => [
                    'ip' => $request->ip(),
                    'user_id' => $user?->getAuthIdentifier(),
                    'user_type' => $user ? get_class($user) : null,
                ],
                'request' => $state['request'],
                'response' => [
                    'status' => $response->getStatusCode(),
                    'headers' => $this->headers($response->headers->all()),
                    'body' => $this->responseBody($response),
                ],
            ]);
        } catch (Throwable $exception) {
            error_log('HTTP logger failed: '.get_class($exception));
        }
    }

    private function payload(array $data): mixed
    {
        return $this->sanitizer->limit(
            $this->sanitizer->redact($data, $this->config->get('http-logger.redacted_fields', [])),
            (int) $this->config->get('http-logger.max_body_bytes', 16384),
        );
    }

    private function headers(array $headers): mixed
    {
        return $this->sanitizer->limit(
            $this->sanitizer->redact($headers, $this->config->get('http-logger.redacted_headers', [])),
            (int) $this->config->get('http-logger.max_body_bytes', 16384),
        );
    }

    private function requestBody(Request $request): mixed
    {
        if ($request->isJson()) {
            return $this->payload($request->json()->all());
        }
        if ($request->request->count() > 0) {
            return $this->payload($request->request->all());
        }

        return ['omitted' => 'unstructured_or_empty_body'];
    }

    /**
     * @throws JsonException
     */
    private function responseBody(Response $response): mixed
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return ['omitted' => 'stream_or_file'];
        }

        $content = $response->getContent();
        if ($content === false || $content === '') {
            return null;
        }

        if (strlen($content) > (int) $this->config->get('http-logger.max_body_bytes', 16384)) {
            return ['omitted' => 'size_limit'];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['omitted' => 'unstructured_body'];
        }
        if (is_array($decoded)) {
            return $this->payload($decoded);
        }

        return ['omitted' => 'unstructured_body'];
    }
}
