# Changelog

All notable changes to `laravel-pennant-analytics` will be documented in this file.

## Unreleased

Findings from user acceptance testing in a real Laravel 13 app:

- The migration is published with a date prefix, so it runs after Pennant's.
- The control never depends on exposure counts: per-feature `controls`
  config, then `control_variants`, then alphabetical order.
- CLI and dashboard show the verdict per variant and the exact significance
  threshold (Bonferroni included); p-values below 0.0001 print as `< 0.0001`.
- Invalid dashboard filters show an error instead of silently redirecting;
  unknown actions are rejected (JSON: 422).
- On phones, dashboard tables become one labelled card per row.

- Date-range reports are cohort based (subjects first exposed in the range,
  with all their conversions); previously they could exceed 100% and drop the
  control variant.
- Names that do not fit the schema throw `InvalidTrackingName` instead of
  being silently dropped.
- Class based and enum features are stored under Pennant's resolved name.
- Hits carry their own timestamp, so queued hits keep their real date.
- Counter writes lock rows in a fixed order and retry deadlocks; no lost
  updates under concurrent load on MariaDB.
- Typed `AnalyticsConfig`, validated at boot.
- Laravel 11 support dropped (end of life, not installable).
- Larastan level 8 in CI.
- Initial release: impression & conversion tracking, first-exposure attribution,
  deferred/queue/sync persistence, auto impressions, route middleware, Blade
  directives, dashboard, two-proportion z-test with Bonferroni correction,
  `pennant-analytics:report` and `pennant-analytics:forget` commands.
