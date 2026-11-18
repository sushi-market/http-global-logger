<?php

declare(strict_types=1);

namespace DF\HttpLogger\Tests;

use DF\HttpLogger\ErrorReporter;
use DF\HttpLogger\HttpLoggerServiceProvider;
use DF\HttpLogger\LogWriter;
use Orchestra\Testbench\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ErrorReportingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [HttpLoggerServiceProvider::class];
    }

    public function test_write_failure_is_reported_to_default_logger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')->willThrowException(new RuntimeException('secret'));
        $logger->expects(self::once())->method('error')->with('http.logger.failed', [
            'stage' => 'incoming.write',
            'exception_type' => RuntimeException::class,
        ]);
        $this->app->instance(LoggerInterface::class, $logger);

        $this->app->make(LogWriter::class)->write('incoming', ['request_id' => '123']);
    }

    public function test_reporter_failure_is_suppressed_and_guard_is_reset(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('error')->willThrowException(new RuntimeException('disk full'));
        $this->app->instance(LoggerInterface::class, $logger);
        $reporter = $this->app->make(ErrorReporter::class);

        $reporter->report(new RuntimeException('secret'), 'incoming.capture');
        $reporter->report(new RuntimeException('secret'), 'outgoing.response');
    }

    public function test_recursive_error_reporting_is_stopped(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $logger);
        $reporter = $this->app->make(ErrorReporter::class);
        $logger->expects(self::once())->method('error')->willReturnCallback(function () use ($reporter): void {
            $reporter->report(new RuntimeException('nested'), 'outgoing.write');
        });

        $reporter->report(new RuntimeException('original'), 'incoming.capture');
    }
}
