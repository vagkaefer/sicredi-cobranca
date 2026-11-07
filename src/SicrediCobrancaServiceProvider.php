<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use VagKaefer\SicrediCobranca\Http\WebhookController;

class SicrediCobrancaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sicredi-cobranca.php', 'sicredi-cobranca');

        $this->app->singleton(SicrediCobranca::class, function ($app) {
            return new SicrediCobranca(
                $app->make(HttpFactory::class),
                $app->make(CacheFactory::class),
                // Lido sob demanda: a config só é validada quando uma operação é feita.
                fn() => Config::fromArray((array) $app['config']->get('sicredi-cobranca', [])),
            );
        });

        $this->app->alias(SicrediCobranca::class, 'sicredi-cobranca');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sicredi-cobranca.php' => config_path('sicredi-cobranca.php'),
            ], 'sicredi-cobranca-config');
        }

        if (config('sicredi-cobranca.webhook.enabled')) {
            Route::post((string) config('sicredi-cobranca.webhook.path', 'sicredi/webhook'), WebhookController::class)
                ->middleware((array) config('sicredi-cobranca.webhook.middleware', ['api']))
                ->name('sicredi-cobranca.webhook');
        }
    }
}
