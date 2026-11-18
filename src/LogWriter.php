<?php

namespace DF\HttpLogger;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use Throwable;

class LogWriter
{
    public function __construct(
        private Application $app,
        private Repository $config,
        private ErrorReporter $reporter,
    ) {}

    public function enabled(string $direction): bool
    {
        return (bool) $this->config->get('http-logger.enabled', true)
            && (bool) $this->config->get("http-logger.$direction.enabled", $direction === 'incoming');
    }

    public function write(string $direction, array $context, string $message = 'http.request'): void
    {
        try {
            $channel = $this->config->get("http-logger.$direction.channel")
                ?? $this->config->get('http-logger.channel');

            $logger = $channel === null
                ? $this->app->make(LoggerInterface::class)
                : $this->app->make('log')->channel($channel);

            $logger->log($this->config->get('http-logger.level', 'info'), $message,
                ['direction' => $direction] + $context);
        } catch (Throwable $exception) {
            $this->report($exception, $direction.'.write');
        }
    }

    public function report(Throwable $exception, string $stage): void
    {
        $this->reporter->report($exception, $stage);
    }
}
