<?php

namespace DF\HttpLogger\Tests;

use DF\HttpLogger\HttpLoggerServiceProvider;
use DF\HttpLogger\Middleware\LogHttpRequest;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

class HttpLoggerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [HttpLoggerServiceProvider::class];
    }

    private function recorder(): AbstractLogger
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = $context;
            }
        };
        $this->app->instance(LoggerInterface::class, $logger);

        return $logger;
    }

    private function exchange(string $uri, array $body = []): array
    {
        $this->app->make(Kernel::class)->prependMiddleware(LogHttpRequest::class);
        $request = Request::create($uri, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer secret',
        ], json_encode($body));
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return [$request, $response];
    }

    public function test_logs_one_redacted_record_with_response_and_timing(): void
    {
        $logger = $this->recorder();
        Route::post('/example', fn () => response()->json([
            'ok' => true, 'nested' => ['token' => 'secret'],
        ])->header('Set-Cookie', 'session=secret'));

        [, $response] = $this->exchange('/example?page=2&token=secret', [
            'name' => 'Alice', 'nested' => ['PASSWORD' => 'secret'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $logger->records);
        $record = $logger->records[0];
        self::assertSame('2', $record['request']['query']['page']);
        self::assertSame('[REDACTED]', $record['request']['query']['token']);
        self::assertSame('[REDACTED]', $record['request']['body']['nested']['PASSWORD']);
        self::assertSame('[REDACTED]', $record['request']['headers']['authorization']);
        self::assertSame('[REDACTED]', $record['response']['headers']['set-cookie']);
        self::assertSame('[REDACTED]', $record['response']['body']['nested']['token']);
        self::assertGreaterThanOrEqual(0, $record['duration_ms']);
        self::assertArrayHasKey('user_id', $record['initiator']);
    }

    public function test_logs_final_error_response(): void
    {
        $logger = $this->recorder();
        Route::post('/error', fn () => abort(403));
        [, $response] = $this->exchange('/error');

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $logger->records);
        self::assertSame(403, $logger->records[0]['response']['status']);
    }

    public function test_excluded_paths_are_not_logged(): void
    {
        $logger = $this->recorder();
        $this->app['config']->set('http-logger.except', ['health*']);
        Route::post('/health', fn () => response()->json(['ok' => true]));
        $this->exchange('/health');
        self::assertSame([], $logger->records);
    }

    public function test_stream_is_not_consumed_and_termination_is_idempotent(): void
    {
        $logger = $this->recorder();
        Route::post('/stream', fn () => response()->stream(function () {
            throw new \RuntimeException('Stream must not be consumed by logger');
        }));
        [$request, $response] = $this->exchange('/stream');
        $this->app->make(LogHttpRequest::class)->terminate($request, $response);

        self::assertCount(1, $logger->records);
        self::assertSame(['omitted' => 'stream_or_file'], $logger->records[0]['response']['body']);
    }

    public function test_large_payload_is_omitted(): void
    {
        $logger = $this->recorder();
        $this->app['config']->set('http-logger.max_body_bytes', 32);
        Route::post('/large', fn () => response()->json(['data' => str_repeat('a', 100)]));
        $this->exchange('/large', ['data' => str_repeat('a', 100)]);
        self::assertSame(['omitted' => 'size_limit'], $logger->records[0]['request']['body']);
        self::assertSame(['omitted' => 'size_limit'], $logger->records[0]['response']['body']);
    }
}
