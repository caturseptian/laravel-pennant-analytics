# Laravel Pennant Analytics

[![tests](https://github.com/caturseptian/laravel-pennant-analytics/actions/workflows/tests.yml/badge.svg)](https://github.com/caturseptian/laravel-pennant-analytics/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/caturseptian/laravel-pennant-analytics.svg)](https://packagist.org/packages/caturseptian/laravel-pennant-analytics)
[![License](https://img.shields.io/packagist/l/caturseptian/laravel-pennant-analytics.svg)](LICENSE.md)

[Laravel Pennant](https://laravel.com/docs/pennant) decides **who sees which variant**. This package tells you **which variant wins**.

It tracks impressions and conversions for your Pennant features, attributes every conversion to the variant the user actually saw, and shows the result on a dashboard with conversion rates and statistical significance. You don't need Mixpanel or Google Analytics for this.

```php
PennantAnalytics::track('new-checkout-ui', 'completed-purchase');
```

> **Variant TRUE is outperforming Variant FALSE by 30.0% (Statistically Significant)**

- **Native DX**: a facade, Blade directives, route middleware, an artisan command, and Pennant's own scopes.
- **Zero request overhead**: hits are buffered in memory and written after the response has been sent, or on a queue.
- **Lean storage**: daily counters updated with atomic increments plus one de-dup row per user. There's no raw event log to grow without limit.
- **Correct statistics**: unique-subject conversion rates, a two-proportion z-test, minimum-sample guards, and a Bonferroni correction for multivariate tests.
- Supports Laravel 12 and 13, PHP 8.2+, and MySQL/MariaDB, PostgreSQL and SQLite.

## Installation

```bash
composer require caturseptian/laravel-pennant-analytics

php artisan vendor:publish --tag=pennant-analytics-migrations
php artisan migrate

# optional
php artisan vendor:publish --tag=pennant-analytics-config
php artisan vendor:publish --tag=pennant-analytics-views
```

## Usage

### 1. Record impressions (who saw which variant)

Choose the style that fits your app. The package resolves the variant from Pennant. You never pass it yourself.

```php
use CaturSeptian\PennantAnalytics\Facades\PennantAnalytics;

// Manually, e.g. in a controller
PennantAnalytics::impression('new-checkout-ui');

// For a whole route (only successful responses are counted)
Route::get('/checkout', CheckoutController::class)
    ->middleware('pennant.impression:new-checkout-ui');
```

```blade
{{-- In a view, next to Pennant's own directive --}}
@feature('new-checkout-ui')
    @trackImpression('new-checkout-ui')
    <x-checkout.v2 />
@else
    @trackImpression('new-checkout-ui')
    <x-checkout.v1 />
@endfeature
```

**Automatic impressions.** Enable this and every Pennant check (`Feature::active()`, `@feature`, …) on the listed features records an impression:

```php
// config/pennant-analytics.php
'auto_impressions' => [
    'enabled' => true,
    'features' => ['new-checkout-ui', 'pricing-page'], // [] = every feature
],
```

Impressions are de-duplicated. A flag checked ten times on one page counts as one impression, and each user counts once in the conversion-rate denominator.

### 2. Record conversions

```php
PennantAnalytics::track('new-checkout-ui', 'completed-purchase');
PennantAnalytics::track('new-checkout-ui', 'clicked-signup');

// Class based and enum features work exactly like in Pennant, and are
// stored under Pennant's resolved name (e.g. the class's $name property).
PennantAnalytics::track(NewCheckout::class, 'completed-purchase');
PennantAnalytics::track(Flags::NewCheckout, 'completed-purchase');
```

Feature names may be up to 150 characters and action names up to 100. A name outside those limits throws `InvalidTrackingName` from the call, so the mistake shows up in development rather than as missing data.

```blade
<form method="post" action="/subscribe">
    @trackConversion('pricing-page', 'viewed-signup-form')
</form>
```

### 3. Custom scopes (teams, tenants, …)

This mirrors Pennant's `Feature::for()`:

```php
PennantAnalytics::for($team)->track('new-billing', 'upgraded');
```

Without an explicit scope the package uses **Pennant's own default scope**, so a `Feature::resolveScopeUsing(...)` call is honoured automatically. When that scope is `null` (a guest), the guest is identified, most stable source first, by:

1. your resolver, if you register one (see below)
2. a UUID stored in their session, on routes with sessions (`web`)
3. IP address + user agent, on stateless routes such as APIs

> **Stateless routes:** the IP + user agent fallback merges guests behind one NAT who use the same browser, and splits a guest whose IP changes. Register a resolver for APIs:

```php
PennantAnalytics::resolveGuestUsing(fn (?Request $request) => $request?->header('X-Device-Id'));
```

### 4. Read the results

Open **`/pennant-analytics`**. Like Horizon and Telescope, the dashboard is limited to the `local` environment by default. To allow access elsewhere:

```php
// AppServiceProvider::boot()
Gate::define('viewPennantAnalytics', fn (User $user) => $user->isAdmin());
```

From the CLI or in code:

```bash
php artisan pennant-analytics:report new-checkout-ui completed-purchase
php artisan pennant-analytics:report new-checkout-ui completed-purchase --from=2026-10-01 --to=2026-10-31
```

```
 Variant          Impressions  Conversions  Rate     Lift     p-value
 false (control)  2,000        200          10.00%   —        —
 true             2,000        260          13.00%   +30.0%   0.0029

Variant TRUE is outperforming Variant FALSE by 30.0% (Statistically Significant)
```

```php
$report = PennantAnalytics::report('new-checkout-ui', 'completed-purchase');

$report->summary();              // the sentence above
$report->hasSignificantWinner(); // true
$report->leader()->conversionRate(); // 13.0
$report->toArray();              // also served as JSON by the dashboard
```

**Date ranges are cohorts.** `--from`/`--to` (and the dashboard's date filter) select the subjects **first exposed** in that range, inclusive, and count every conversion *those* subjects made, even after the range ends. "September" therefore means "users who entered the experiment in September and how they converted". Counting conversions by the day they happened instead would mix two different populations, and could even show rates above 100%. Cohort reports read the `subjects` table, so they are heavier than all-time reports, and they leave `total_conversions` empty because repeat events aren't stored per subject.

Invalid dashboard filters (a malformed date, or an action that was never recorded) are reported on the page above the unfiltered report, and the JSON view returns them as `422` errors.

When an experiment is over, run `php artisan pennant-analytics:forget new-checkout-ui` to delete its data.

## How it works

### Database architecture

There are two narrow tables instead of one ever-growing event log:

| `pennant_analytics_subjects` | |
|---|---|
| `feature`, `action`, `subject` | **unique**: atomic de-dup via `INSERT … ON CONFLICT DO NOTHING` |
| `variant` | the variant this subject was first exposed to (attribution) |
| `created_at` | time of the first hit; cohort date ranges filter on it |

| `pennant_analytics_metrics` | |
|---|---|
| `feature`, `variant`, `action`, `date` | **unique**: one row per day per combination |
| `total` | every hit (e.g. repeat purchases) |
| `uniques` | first hit per subject → conversion-rate numerator/denominator |

Counters are bumped with `UPDATE … SET total = total + ?`. Only when the day's row doesn't exist yet is it inserted (insert-or-ignore, then update again if a concurrent request won). Each batch is one transaction that locks rows in a fixed, sorted order, and it is retried up to 3 times on a deadlock. This was stress-tested with 16 concurrent writers on MariaDB and PostgreSQL, and no updates were lost. The dashboard only reads `metrics`, so reports stay fast regardless of traffic. `subjects` grows with *users × experiments × actions*, not with page views.

`subject` is an `xxh128` digest of Pennant's serialized scope (`App\Models\User|42`) or of the guest id. It keeps the indexed column at a fixed width. It is *not* anonymization for guessable ids.

### Request lifecycle

1. `track()` asks Pennant for the value. Pennant memoizes values per request, so this is usually free. Pennant doesn't expose its resolved default scope publicly, so `PennantResolver` reads it from the `FeatureRetrieved` event Pennant fires for that lookup.
2. A small `Hit` value object, carrying its own timestamp, is appended to an in-memory buffer. A queued hit is therefore dated when it happened, not when a worker processed it.
3. The buffer is flushed according to `persistence`:
   - **`deferred`** (default): on `app()->terminating()`, after the response has been sent to the browser. Queue workers flush after each job.
   - **`queue`**: a single `PersistHits` job per request, carrying plain arrays only. Queues deliver *at least once*: if a worker dies after the batch is committed but before the job is acknowledged, the retry counts that batch again. Expect a rare over-count, never a lost hit.
   - **`sync`**: immediately, which suits tests and scripts.
4. Error policy: runtime failures (database down, a Pennant store error) are passed to `report()` and never thrown, so analytics can't break your checkout. Programmer errors (`InvalidTrackingName`) are thrown.

### Attribution

A conversion is credited to the variant the subject **first saw**, read from the `subjects` table, even if the flag's value later changes for that user. A conversion from a subject with no recorded impression implicitly records one, so a conversion rate can never exceed 100%.

### The math

Conversion rate per variant, over **unique** subjects:

```
rate = (conversions / impressions) * 100
```

Each non-control variant is compared with the control using a pooled **two-proportion z-test**:

```
p_c = x_c / n_c            p_v = x_v / n_v
p   = (x_c + x_v) / (n_c + n_v)
SE  = sqrt( p (1 - p) (1/n_c + 1/n_v) )
z   = (p_v - p_c) / SE
p-value = 2 · (1 - Φ(|z|))          two-tailed
lift    = (p_v - p_c) / p_c · 100
```

For a 2×2 table, `z²` equals Pearson's chi-square statistic, so this covers the chi-square test too, and the test suite checks it. `Φ` uses a Chebyshev `erfc` approximation with error below 1.2e-7, so no extensions are needed.

A variant is reported as significant only if **all** of these hold:

- `p-value < 1 - confidence` (default 95%). With *k* challengers the threshold is divided by *k* (Bonferroni), so a 4-way test doesn't produce "winners" by chance.
- Each group has at least `min_sample_size` impressions (default 100).
- Each group has at least 5 expected successes and 5 expected failures, which the normal approximation needs.

Until then the dashboard shows *Insufficient data* instead of a misleading verdict. The page also states the exact threshold in use, e.g. "Significant when p < 0.025 (95% confidence, Bonferroni-corrected for 2 comparisons)". p-values below the display precision are shown as `< 0.0001`.

**The control (baseline)** is chosen in this order:

1. the control you configure for the feature: `'controls' => ['pricing-page' => 'monthly']`
2. the first variant matching `control_variants` (`false`, `control`, `off`, `baseline`)
3. otherwise the alphabetically first variant

Exposure counts never decide the control. Otherwise one extra visitor could flip the baseline and change every verdict. Configure `controls` for every multivariate experiment.

### Long-running processes

Pennant keeps evaluated flag values in memory and searches them linearly. In a loop that touches thousands of users in one process (imports, backfills, queue workers), each lookup gets slower: 5 → 12 → 19 ms per user over 3,000 users in our profiling. This package added no measurable overhead in the same test. Flush Pennant's cache as you go:

```php
foreach ($users as $user) {
    PennantAnalytics::for($user)->impression('new-checkout-ui');
    Feature::flushCache(); // keeps it flat at ~0.5 ms per user
}
```

## Configuration

See [`config/pennant-analytics.php`](config/pennant-analytics.php). Every option is documented inline: master switch, persistence and queue, DB connection and table names, impression action name, auto impressions, statistics thresholds, and dashboard path/domain/middleware.

## Testing

```bash
composer test
composer analyse

# against a real database
DB_CONNECTION=pgsql DB_DATABASE=testing composer test
DB_CONNECTION=mysql DB_DATABASE=testing composer test
```

CI runs Pint and Larastan (level 8), and the suite on PHP 8.2–8.4 × Laravel 12–13 × SQLite/MySQL/PostgreSQL.

Laravel 11 is not supported. It reached end of life, and Composer refuses to install any 11.x release because of open security advisories.

## Credits

- [Catur Septian](https://github.com/caturseptian)
- Built on [Laravel Pennant](https://github.com/laravel/pennant)

## License

MIT. See [LICENSE.md](LICENSE.md).
