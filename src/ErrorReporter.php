<?php

declare(strict_types=1);

namespace DF\HttpLogger;

use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use Throwable;

class ErrorReporter
{
    private bool $reporting = false;

    public function __construct(private Application $app) {}

    public function report(Throwable $exception, string $stage): void
    {
        if ($this->reporting) {
            return;
        }

        $this->reporting = true;
        try {
            $this->app->make(LoggerInterface::class)->error('http.logger.failed', [
                'stage' => $stage,
                'exception_type' => get_class($exception),
            ]);
        } catch (Throwable) {
            // The default logger may also be unavailable. Never recurse or interrupt work.
        } finally {
            $this->reporting = false;
        }
    }
}
