<?php

namespace DF\HttpLogger\Middleware;

use Closure;
use DF\HttpLogger\HttpPayload;
use DF\HttpLogger\LogWriter;
use DF\HttpLogger\TraceContext;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LogHttpRequest
{
    private const STATE = '_http_logger';

    public function __construct(
        private Repository $config,
        private HttpPayload $payload,
        private LogWriter $writer,
        private TraceContext $trace,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->writer->enabled('incoming') && !$this->writer->enabled('outgoing')) {
            return $next($request);
        }

        $traceId = $this->trace->id();
        try {
            $traceId = $this->trace->valid($request->header(
                $this->config->get('http-logger.trace_header', 'X-Trace-Id')
            )) ?? (string) Str::uuid();

            if (
                $this->writer->enabled('incoming')
                && !$request->attributes->has(self::STATE)
                && !$request->is(...$this->config->get('http-logger.except', []))
            ) {
                $requestId = $this->trace->valid($request->header(
                    $this->config->get('http-logger.request_header', 'X-Request-Id')
                )) ?? (string) Str::uuid();

                $state = [
                    'start' => hrtime(true),
                    'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'request_id' => $requestId,
                    'trace_id' => $traceId,
                ];
                $request->attributes->set(self::STATE, $state);
                $started = $state;
                unset($started['start']);
                $this->writer->write('incoming', $started + [
                    'event' => 'started',
                    'initiator' => [
                        'ip' => $request->ip(),
                        // Authentication middleware may not have run yet.
                        'user_id' => null,
                        'user_type' => null,
                    ],
                    'request' => [
                        'method' => $request->method(),
                        'path' => $request->getPathInfo(),
                        'query' => $this->payload->data($request->query()),
                        'body' => $this->requestBody($request),
                        'headers' => $this->payload->data($request->headers->all(), true),
                    ],
                ], 'http.request.started');
            }
        } catch (Throwable $exception) {
            $this->writer->report($exception, 'incoming.capture');
        }

        return $this->trace->run($traceId, fn () => $next($request));
    }

    public function terminate(Request $request, Response $response): void
    {
        $state = $request->attributes->get(self::STATE);
        if (!is_array($state)) {
            return;
        }

        $request->attributes->remove(self::STATE);
        try {
            $duration = round((hrtime(true) - $state['start']) / 1_000_000, 3);
            unset($state['start']);
            $user = $request->user();

            $this->writer->write('incoming', $state + [
                'event' => 'finished',
                'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'duration_ms' => $duration,
                'initiator' => [
                    'ip' => $request->ip(),
                    'user_id' => $user?->getAuthIdentifier(),
                    'user_type' => $user ? get_class($user) : null,
                ],
                'response' => [
                    'status' => $response->getStatusCode(),
                    'headers' => $this->payload->data($response->headers->all(), true),
                    'body' => $this->responseBody($response),
                ],
            ], 'http.request.finished');
        } catch (Throwable $exception) {
            $this->writer->report($exception, 'incoming.response');
        }
    }

    private function requestBody(Request $request): mixed
    {
        if ($request->isJson()) {
            return $this->payload->data($request->json()->all());
        }

        return $request->request->count() > 0
            ? $this->payload->data($request->request->all())
            : ['omitted' => 'unstructured_or_empty_body'];
    }

    private function responseBody(Response $response): mixed
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return ['omitted' => 'stream_or_file'];
        }

        $content = $response->getContent();
        if ($content === false || $content === '') {
            return null;
        }

        if (strlen($content) > $this->payload->limit()) {
            return ['omitted' => 'size_limit'];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $this->payload->data($decoded) : ['omitted' => 'unstructured_body'];
    }
}
