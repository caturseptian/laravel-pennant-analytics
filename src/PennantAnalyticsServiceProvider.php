<?php

namespace CaturSeptian\PennantAnalytics;

use CaturSeptian\PennantAnalytics\Commands\ForgetCommand;
use CaturSeptian\PennantAnalytics\Commands\ReportCommand;
use CaturSeptian\PennantAnalytics\Http\Middleware\TrackFeatureImpressions;
use CaturSeptian\PennantAnalytics\Storage\MetricsRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Events\FeatureRetrieved;

class PennantAnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pennant-analytics.php', 'pennant-analytics');

        $this->app->singleton(MetricsRepository::class, fn (Application $app) => new MetricsRepository(
            $app['db'],
            $app['config']->get('pennant-analytics.connection'),
            $app['config']->get('pennant-analytics.tables'),
            $app['config']->get('pennant-analytics.impression_action'),
        ));

        // A singleton so the per-request buffer is shared by every caller.
        // It is drained on terminate, which also keeps it Octane-safe.
        $this->app->singleton(PennantAnalyticsEngine::class, fn (Application $app) => new PennantAnalyticsEngine(
            $app,
            $app->make(MetricsRepository::class),
            $app['config']->get('pennant-analytics'),
        ));
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerEventHooks();
        $this->registerBladeDirectives();
        $this->registerMiddleware();
        $this->registerDashboard();

        if ($this->app->runningInConsole()) {
            $this->commands([ReportCommand::class, ForgetCommand::class]);
        }
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/pennant-analytics.php' => config_path('pennant-analytics.php'),
        ], 'pennant-analytics-config');

        // publishesMigrations() stamps the file with the current date on publish.
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'pennant-analytics-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/pennant-analytics'),
        ], 'pennant-analytics-views');
    }

    /**
     * Wire the engine into the framework lifecycle:
     *  - Pennant's FeatureRetrieved event (scope capture + auto impressions)
     *  - application terminate (after the response is sent) for flushing
     *  - queue job completion, because long-lived workers never "terminate"
     */
    protected function registerEventHooks(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $events->listen(FeatureRetrieved::class, fn (FeatureRetrieved $event) => $this->app->make(PennantAnalyticsEngine::class)->handleFeatureRetrieved($event));

        $flush = function () {
            if ($this->app->resolved(PennantAnalyticsEngine::class)) {
                $this->app->make(PennantAnalyticsEngine::class)->flush();
            }
        };

        $this->app->terminating($flush);
        $events->listen([JobProcessed::class, JobFailed::class], $flush);
    }

    /**
     * Blade directives: `@trackImpression('new-checkout-ui')` and
     * `@trackConversion('new-checkout-ui', 'clicked-signup')`.
     */
    protected function registerBladeDirectives(): void
    {
        Blade::directive('trackImpression', fn (string $expression) => "<?php \\CaturSeptian\\PennantAnalytics\\Facades\\PennantAnalytics::impression({$expression}); ?>");
        Blade::directive('trackConversion', fn (string $expression) => "<?php \\CaturSeptian\\PennantAnalytics\\Facades\\PennantAnalytics::track({$expression}); ?>");
    }

    protected function registerMiddleware(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('pennant.impression', TrackFeatureImpressions::class);
    }

    protected function registerDashboard(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'pennant-analytics');

        // Local-only by default, Horizon/Telescope style. Apps override this.
        if (! Gate::has('viewPennantAnalytics')) {
            Gate::define('viewPennantAnalytics', fn ($user = null) => $this->app->environment('local'));
        }

        $config = $this->app['config']->get('pennant-analytics.dashboard');

        if (! ($config['enabled'] ?? true) || $this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'prefix' => $config['path'],
            'domain' => $config['domain'] ?? null,
            'middleware' => $config['middleware'],
            'as' => 'pennant-analytics.',
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/web.php'));
    }
}
