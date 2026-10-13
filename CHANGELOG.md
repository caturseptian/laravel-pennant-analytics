# Changelog

All notable changes to `laravel-pennant-analytics` will be documented in this file.

## v1.0.0 - 2026-10-13

First release.

### Tracking

- `PennantAnalytics::impression()` and `PennantAnalytics::track()` facade methods, plus
  `PennantAnalytics::for($scope)` for teams, tenants and other scopes.
- Automatic impressions from Pennant checks (`auto_impressions` config), the
  `pennant.impression` route middleware, and the `@trackImpression` / `@trackConversion`
  Blade directives.
- Honours Pennant's default scope and `Feature::resolveScopeUsing()`; guests are identified
  by a session UUID, or by your own resolver (`resolveGuestUsing()`).
- Class-based and enum features are stored under Pennant's resolved name.
- Conversions are attributed to the variant the subject first saw.
- Names that do not fit the schema throw `InvalidTrackingName`; runtime failures are
  reported and never break the request.

### Storage and performance

- Two narrow tables: one de-duplication row per subject, and daily counters.
- Hits are written after the response (`deferred`), on a queue (`queue`) or inline (`sync`).
  Each hit carries its own timestamp, so queued hits keep their real date.
- Race-free counter updates; rows are locked in a fixed order and deadlocks are retried.
  Tested with 16 concurrent writers on MariaDB and PostgreSQL.

### Reporting

- Dashboard (gate `viewPennantAnalytics`, local-only by default) with a JSON view, and the
  `pennant-analytics:report` and `pennant-analytics:forget` commands.
- Two-proportion z-test with a Bonferroni correction for multivariate tests, minimum sample
  guards, and a verdict per variant with the exact significance threshold.
- A stable control: per-feature `controls` config, then `control_variants`, then
  alphabetical order. It never depends on exposure counts.
- Date ranges are cohorts: subjects first exposed in the range, with all their conversions.
- Invalid dashboard filters are reported on the page (JSON: 422).
- Responsive dashboard: one labelled card per row on phones.

### Requirements

- PHP 8.2+, Laravel 12 or 13, Laravel Pennant 1.10+.
- Tested on SQLite, MySQL/MariaDB and PostgreSQL.
