<?php

namespace DF\HttpLogger;

use DF\HttpLogger\Client\LogOutgoingRequest;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

class HttpLoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/http-logger.php', 'http-logger');
        $this->app->scoped(TraceContext::class, fn () => new TraceContext());
        $this->app->singleton(ErrorReporter::class);
    }

    public function boot(): void
    {
        // Resolve context per attempt, never capture a scoped context in the factory.
        $this->callAfterResolving(Factory::class, function (Factory $factory): void {
            $factory->globalMiddleware(new LogOutgoingRequest($this->app));
        });

        $this->publishes([
            __DIR__.'/../config/http-logger.php' => config_path('http-logger.php'),
        ], 'http-logger-config');
    }
}
