<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    |
    | When disabled, every tracking call becomes a no-op. Handy for test suites
    | or for temporarily muting analytics without touching application code.
    |
    */

    'enabled' => env('PENNANT_ANALYTICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Persistence Strategy
    |--------------------------------------------------------------------------
    |
    | How buffered hits reach the database:
    |
    |  - "deferred": buffered in memory and written after the response has been
    |                sent to the browser (on application terminate). Default.
    |  - "queue":    buffered, then handed to a queued job at terminate time.
    |  - "sync":     written immediately (useful for tests and CLI scripts).
    |
    */

    'persistence' => env('PENNANT_ANALYTICS_PERSISTENCE', 'deferred'),

    'queue' => [
        'connection' => env('PENNANT_ANALYTICS_QUEUE_CONNECTION'),
        'name' => env('PENNANT_ANALYTICS_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | "subjects" holds one row per (feature, action, subject) and powers unique
    | counting and first-exposure attribution. "metrics" holds daily counters
    | per (feature, variant, action, date) and powers the dashboard.
    |
    */

    'connection' => env('PENNANT_ANALYTICS_DB_CONNECTION'),

    'tables' => [
        'subjects' => 'pennant_analytics_subjects',
        'metrics' => 'pennant_analytics_metrics',
    ],

    /*
    |--------------------------------------------------------------------------
    | Impression Action
    |--------------------------------------------------------------------------
    |
    | The reserved action name used for exposures. Conversion rates are always
    | computed as unique converters / unique subjects with this action.
    |
    */

    'impression_action' => 'impression',

    /*
    |--------------------------------------------------------------------------
    | Automatic Impressions
    |--------------------------------------------------------------------------
    |
    | When enabled, every Pennant check (Feature::active(), @feature, ...) for
    | the listed features records an impression via Pennant's FeatureRetrieved
    | event. An empty "features" array means "every feature" - be deliberate,
    | kill-switch style flags rarely need analytics.
    |
    */

    'auto_impressions' => [
        'enabled' => env('PENNANT_ANALYTICS_AUTO_IMPRESSIONS', false),
        'features' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    |
    | "confidence"       - required confidence level for significance (0.95 = 95%).
    | "min_sample_size"  - minimum impressions per variant before a verdict.
    | "control_variants" - variant values treated as the baseline, first match
    |                      wins. Falls back to the variant with most impressions.
    |
    */

    'statistics' => [
        'confidence' => 0.95,
        'min_sample_size' => 100,
        'control_variants' => ['false', 'control', 'off', 'baseline'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Access is guarded by the "viewPennantAnalytics" gate, which only allows
    | the local environment by default. Override it in a service provider:
    |
    |   Gate::define('viewPennantAnalytics', fn ($user) => $user->isAdmin());
    |
    */

    'dashboard' => [
        'enabled' => env('PENNANT_ANALYTICS_DASHBOARD', true),
        'path' => env('PENNANT_ANALYTICS_PATH', 'pennant-analytics'),
        'domain' => env('PENNANT_ANALYTICS_DOMAIN'),
        'middleware' => ['web', 'can:viewPennantAnalytics'],
    ],

];
