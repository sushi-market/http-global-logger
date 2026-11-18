<?php

namespace DF\HttpLogger\Tests;

use DF\HttpLogger\Client\LogOutgoingRequest;
use DF\HttpLogger\HttpLoggerServiceProvider;
use DF\HttpLogger\Middleware\LogHttpRequest;
use DF\HttpLogger\TraceContext;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

class OutgoingTest extends TestCase
{
    private AbstractLogger $logger;

    protected function getPackageProviders($app): array
    {
        return [HttpLoggerServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('http-logger.outgoing.enabled', true);
        $this->logger = new class extends AbstractLogger {
            public array $records = [];
            public bool $fail = false;
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($this->fail) {
                    throw new RuntimeException('Logger unavailable');
                }
                $this->records[] = $context;
            }
        };
        $this->app->instance(LoggerInterface::class, $this->logger);
        Http::preventStrayRequests();
    }

    public function test_both_directions_share_trace_but_have_different_request_ids(): void
    {
        Http::fake(['*' => Http::response(['token' => 'secret', 'ok' => true])]);
        $request = Request::create('/api/example', 'GET', [], [], [], [
            'HTTP_X_TRACE_ID' => 'trace-123',
            'HTTP_X_REQUEST_ID' => 'incoming-123',
        ]);

        $middleware = $this->app->make(LogHttpRequest::class);
        $response = $middleware->handle($request, function () {
            $response = Http::withToken('secret')->post('https://example.test/api?token=secret', [
                'password' => 'secret', 'name' => 'Alice',
            ]);
            self::assertTrue($response->json('ok'));
            return response()->json(['ok' => true]);
        });
        $middleware->terminate($request, $response);
        self::assertCount(3, $this->logger->records);
        [$started, $out, $in] = $this->logger->records;
        self::assertSame('started', $started['event']);
        self::assertSame('finished', $in['event']);
        self::assertSame($started['request_id'], $in['request_id']);
        self::assertSame('outgoing', $out['direction']);
        self::assertSame('incoming', $in['direction']);
        self::assertSame('trace-123', $out['trace_id']);
        self::assertSame($in['trace_id'], $out['trace_id']);
        self::assertSame('incoming-123', $in['request_id']);
        self::assertNotSame($in['request_id'], $out['request_id']);
        self::assertSame('[REDACTED]', $out['request']['query']['token']);
        self::assertSame('[REDACTED]', $out['request']['body']['password']);
        self::assertSame('[REDACTED]', $out['response']['body']['token']);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Trace-Id', 'trace-123')
            && $r->hasHeader('X-Request-Id', $out['request_id']));
    }

    public function test_incoming_can_be_disabled_without_breaking_outgoing_trace(): void
    {
        $this->app['config']->set('http-logger.incoming.enabled', false);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $request = Request::create('/api', 'GET', [], [], [], ['HTTP_X_TRACE_ID' => 'trace-only']);
        $middleware = $this->app->make(LogHttpRequest::class);
        $response = $middleware->handle($request, function () {
            Http::get('https://example.test');
            return response('');
        });

        $middleware->terminate($request, $response);
        self::assertCount(1, $this->logger->records);
        self::assertSame('trace-only', $this->logger->records[0]['trace_id']);
    }

    public function test_outgoing_disabled_leaves_headers_and_response_unchanged(): void
    {
        $this->app['config']->set('http-logger.outgoing.enabled', false);
        Http::fake(['*' => Http::response(['ok' => true])]);
        self::assertTrue(Http::get('https://example.test')->json('ok'));
        Http::assertSent(fn ($r) => !$r->hasHeader('X-Trace-Id') && !$r->hasHeader('X-Request-Id'));
        self::assertSame([], $this->logger->records);
    }

    public function test_master_switch_disables_both(): void
    {
        $this->app['config']->set('http-logger.enabled', false);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $middleware = $this->app->make(LogHttpRequest::class);
        $request = Request::create('/api');
        $response = $middleware->handle($request, function () {
            Http::get('https://example.test');
            return response('');
        });

        $middleware->terminate($request, $response);
        self::assertSame([], $this->logger->records);
    }

    public function test_connection_exception_still_reaches_caller(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        try {
            Http::get('https://example.test');
            self::fail('Expected connection exception');
        } catch (ConnectionException) {
            self::assertCount(1, $this->logger->records);
            self::assertNull($this->logger->records[0]['response']);
            self::assertNotNull($this->logger->records[0]['error']);
        }
    }

    public function test_retry_has_independent_records_and_request_ids(): void
    {
        Http::fake(['*' => Http::sequence()->push(['error' => true], 500)->push(['ok' => true])]);
        self::assertTrue(Http::retry(2, 0)->get('https://example.test')->json('ok'));
        self::assertCount(2, $this->logger->records);

        [$a, $b] = $this->logger->records;

        self::assertNotSame($a['request_id'], $b['request_id']);
        self::assertSame($a['trace_id'], $b['trace_id']);
        self::assertSame(500, $a['response']['status']);
        self::assertSame(200, $b['response']['status']);
    }

    public function test_pool_requests_have_independent_state(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $responses = Http::pool(fn (Pool $pool) => [
            $pool->get('https://example.test/a'), $pool->get('https://example.test/b'),
        ]);

        self::assertCount(2, $responses);
        self::assertCount(2, $this->logger->records);
        self::assertNotSame($this->logger->records[0]['request_id'], $this->logger->records[1]['request_id']);
        self::assertSame($this->logger->records[0]['trace_id'], $this->logger->records[1]['trace_id']);
    }

    public function test_response_and_request_stream_positions_are_preserved(): void
    {
        $body = Utils::streamFor('{"ok":true}');
        $body->seek(3);
        $response = new PsrResponse(200, ['Content-Type' => 'application/json'], $body);
        $requestBody = Utils::streamFor('{"password":"secret"}');
        $requestBody->seek(2);
        $request = new PsrRequest('POST', 'https://example.test', ['Content-Type' => 'application/json'], $requestBody);
        $handler = ($this->app->make(LogOutgoingRequest::class))(fn () => Create::promiseFor($response));
        $actual = $handler($request, [])->wait();

        self::assertSame($response, $actual);
        self::assertSame(3, $body->tell());
        self::assertSame(2, $requestBody->tell());
        $body->rewind();
        self::assertSame('{"ok":true}', $body->getContents());
    }

    public function test_non_seekable_and_streamed_bodies_are_not_read(): void
    {
        foreach ([[], ['stream' => true], ['sink' => '/unused']] as $options) {
            $stream = new NoSeekStream(Utils::streamFor('{"ok":true}'));
            $response = new PsrResponse(200, ['Content-Type' => 'application/json'], $stream);
            $handler = ($this->app->make(LogOutgoingRequest::class))(fn () => Create::promiseFor($response));

            self::assertSame($response, $handler(new PsrRequest('GET', 'https://example.test'), $options)->wait());
            self::assertSame(0, $stream->tell());
            self::assertSame('{"ok":true}', $stream->getContents());
        }
    }

    public function test_logging_failure_does_not_change_response_or_exception_identity(): void
    {
        $this->logger->fail = true;
        $request = new PsrRequest('GET', 'https://example.test');
        $response = new PsrResponse(503);
        $middleware = $this->app->make(LogOutgoingRequest::class);
        self::assertSame($response, $middleware(fn () => Create::promiseFor($response))($request, [])->wait());
        $exception = new ConnectException('transport failure', $request);

        try {
            $middleware(fn () => Create::rejectionFor($exception))($request, [])->wait();
            self::fail('Expected original failure');
        } catch (ConnectException $actual) {
            self::assertSame($exception, $actual);
        }
    }

    public function test_application_exception_is_not_swallowed_and_trace_is_restored(): void
    {
        $trace = $this->app->make(TraceContext::class);
        $previous = $trace->id();
        $exception = new RuntimeException('application failure');

        try {
            $this->app->make(LogHttpRequest::class)->handle(Request::create('/api'), function () use ($exception) {
                throw $exception;
            });
            self::fail('Expected application exception');
        } catch (RuntimeException $actual) {
            self::assertSame($exception, $actual);
        }
        self::assertSame($previous, $trace->id());
    }

    public function test_new_scope_gets_new_trace(): void
    {
        $first = $this->app->make(TraceContext::class)->id();
        $this->app->forgetScopedInstances();
        self::assertNotSame($first, $this->app->make(TraceContext::class)->id());
    }

    public function test_seekable_stream_and_sink_responses_are_not_consumed(): void
    {
        foreach ([['stream' => true], ['sink' => '/unused']] as $options) {
            $stream = Utils::streamFor('{"ok":true}');
            $stream->seek(4);
            $response = new PsrResponse(200, ['Content-Type' => 'application/json'], $stream);
            $handler = ($this->app->make(LogOutgoingRequest::class))(fn () => Create::promiseFor($response));

            self::assertSame($response, $handler(new PsrRequest('GET', 'https://example.test'), $options)->wait());
            self::assertSame(4, $stream->tell());
            self::assertSame(['omitted' => 'stream_or_file'], end($this->logger->records)['response']['body']);
        }
    }

    public function test_sequential_incoming_requests_do_not_share_trace(): void
    {
        $middleware = $this->app->make(LogHttpRequest::class);
        foreach (['first', 'second'] as $path) {
            $request = Request::create('/'.$path);
            $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));
            $middleware->terminate($request, $response);
        }

        self::assertCount(4, $this->logger->records);
        self::assertNotSame($this->logger->records[0]['trace_id'], $this->logger->records[2]['trace_id']);
    }

    public function test_incoming_logging_failure_does_not_change_response(): void
    {
        $this->logger->fail = true;
        $middleware = $this->app->make(LogHttpRequest::class);
        $request = Request::create('/api');
        $response = response()->json(['ok' => true], 202);

        self::assertSame($response, $middleware->handle($request, fn () => $response));
        $middleware->terminate($request, $response);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('{"ok":true}', $response->getContent());
    }
}
