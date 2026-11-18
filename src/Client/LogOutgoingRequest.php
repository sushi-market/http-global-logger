<?php

namespace DF\HttpLogger\Client;

use DF\HttpLogger\HttpPayload;
use DF\HttpLogger\ErrorReporter;
use DF\HttpLogger\LogWriter;
use DF\HttpLogger\TraceContext;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class LogOutgoingRequest
{
    public function __construct(private Application $app) {}

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $state = null;

            try {
                $writer = $this->app->make(LogWriter::class);

                if ($writer->enabled('outgoing')) {
                    $config = $this->app->make('config');
                    $trace = $this->app->make(TraceContext::class);
                    $payload = $this->app->make(HttpPayload::class);
                    $traceHeader = $config->get('http-logger.trace_header', 'X-Trace-Id');
                    $requestHeader = $config->get('http-logger.request_header', 'X-Request-Id');
                    $traceId = $trace->valid($request->getHeaderLine($traceHeader)) ?? $trace->id();
                    $requestId = (string) Str::uuid();
                    $request = $request->withHeader($traceHeader, $traceId)->withHeader($requestHeader, $requestId);
                    $uri = $request->getUri();
                    parse_str($uri->getQuery(), $query);

                    $state = [
                        'start' => hrtime(true),
                        'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
                        'request_id' => $requestId,
                        'trace_id' => $traceId,
                        'request' => [
                            'method' => $request->getMethod(),
                            'url' => (string) $uri->withQuery('')->withUserInfo('')->withFragment(''),
                            'query' => $payload->data($query),
                            'headers' => $payload->data($request->getHeaders(), true),
                            'body' => $payload->stream($request->getBody(), $request->getHeaderLine('Content-Type')),
                        ],
                    ];
                }
            } catch (Throwable $exception) {
                $this->report($exception, 'outgoing.capture');
            }

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $exception) {
                $this->record($state, null, $exception, $options);
                throw $exception;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($state, $options) {
                    $this->record($state, $response, null, $options);
                    return $response;
                },

                function ($reason) use ($state, $options) {
                    $this->record($state, null, $reason, $options);
                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private function record(?array $state, ?ResponseInterface $response, mixed $error, array $options): void
    {
        if ($state === null) {
            return;
        }

        try {
            $duration = round((hrtime(true) - $state['start']) / 1_000_000, 3);
            unset($state['start']);
            $payload = $this->app->make(HttpPayload::class);

            $this->app->make(LogWriter::class)->write('outgoing', $state + [
                'duration_ms' => $duration,
                'response' => $response === null ? null : [
                    'status' => $response->getStatusCode(),
                    'headers' => $payload->data($response->getHeaders(), true),
                    'body' => $payload->stream(
                        $response->getBody(),
                        $response->getHeaderLine('Content-Type'),
                        !empty($options['stream']) || isset($options['sink']),
                    ),
                ],

                // Exception messages may contain URLs, credentials or payloads.
                'error' => $error === null ? null : [
                    'type' => is_object($error) ? get_class($error) : get_debug_type($error),
                ],
            ]);
        } catch (Throwable $exception) {
            $this->report($exception, 'outgoing.response');
        }
    }
    private function report(Throwable $exception, string $stage): void
    {
        try {
            $this->app->make(ErrorReporter::class)->report($exception, $stage);
        } catch (Throwable) {
            // Container failure must not prevent the actual HTTP request.
        }
    }
}
