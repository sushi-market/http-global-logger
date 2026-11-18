<?php

declare(strict_types=1);

namespace DF\HttpLogger;

use Illuminate\Support\ServiceProvider;

class HttpLoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/http-logger.php', 'http-logger');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/http-logger.php' => config_path('http-logger.php'),
        ], 'http-logger-config');
    }
}
