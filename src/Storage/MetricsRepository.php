<?php

namespace CaturSeptian\PennantAnalytics\Storage;

use Carbon\CarbonImmutable;
use CaturSeptian\PennantAnalytics\Support\Hit;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The only class that talks to the database.
 *
 * Writes are race-free without application-level locks:
 *  - uniqueness is decided by the database ("insert or ignore" on a unique key)
 *  - counters are bumped with "UPDATE ... SET total = total + ?"
 *  - rows are always locked in the same (sorted) order, so concurrent batches
 *    queue behind each other instead of deadlocking, and the rare deadlock
 *    that remains (e.g. InnoDB gap locks) is retried by the transaction.
 */
class MetricsRepository
{
    /**
     * @param  array{subjects: string, metrics: string}  $tables
     */
    public function __construct(
        protected DatabaseManager $db,
        protected ?string $connection,
        protected array $tables,
        protected string $impressionAction,
    ) {}

    /** Attempts for a batch that hits a deadlock or serialization failure. */
    public const TRANSACTION_ATTEMPTS = 3;

    /**
     * Persist a batch of hits atomically: either every counter and subject
     * row of the batch is written, or none is.
     *
     * @param  iterable<Hit>  $hits
     */
    public function persist(iterable $hits): void
    {
        $hits = $this->lockOrder($hits);

        $this->connection()->transaction(function () use ($hits) {
            /** @var array<string, array{feature: string, variant: string, action: string, date: string, total: int, uniques: int}> $counters */
            $counters = [];

            foreach ($hits as $hit) {
                foreach ($this->expand($hit) as [$resolved, $isNew]) {
                    $key = implode("\0", [$resolved->feature, $resolved->variant, $resolved->action, $resolved->date()]);

                    $counters[$key] ??= [
                        'feature' => $resolved->feature,
                        'variant' => $resolved->variant,
                        'action' => $resolved->action,
                        'date' => $resolved->date(),
                        'total' => 0,
                        'uniques' => 0,
                    ];

                    $counters[$key]['total']++;
                    $counters[$key]['uniques'] += (int) $isNew;
                }
            }

            ksort($counters, SORT_STRING);

            foreach ($counters as $counter) {
                $this->increment($counter);
            }
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Sort hits by the subject row they lock. The sort is stable, so for one
     * subject an impression still precedes the conversion that follows it.
     *
     * @param  iterable<Hit>  $hits
     * @return list<Hit>
     */
    protected function lockOrder(iterable $hits): array
    {
        $hits = is_array($hits) ? array_values($hits) : iterator_to_array($hits, false);

        usort($hits, fn (Hit $a, Hit $b) => [$a->feature, $a->subject] <=> [$b->feature, $b->subject]);

        return $hits;
    }

    /**
     * Turn one hit into the rows it should count towards.
     *
     * Conversions are attributed to the variant the subject was *first exposed
     * to* (first-exposure attribution), which keeps results honest when a flag's
     * rollout changes mid-experiment. A conversion from a subject with no
     * recorded exposure implicitly records one, so a conversion rate can never
     * exceed 100%.
     *
     * @return list<array{0: Hit, 1: bool}>
     */
    protected function expand(Hit $hit): array
    {
        if ($hit->isImpression) {
            return [[$hit, $this->claimSubject($hit)]];
        }

        $rows = [];

        $exposedVariant = $this->subjects()
            ->where('feature', $hit->feature)
            ->where('action', $this->impressionAction)
            ->where('subject', $hit->subject)
            ->value('variant');

        if ($exposedVariant === null) {
            $impression = $hit->asImpression($this->impressionAction);

            $rows[] = [$impression, $this->claimSubject($impression)];
        } else {
            $hit = $hit->withVariant($exposedVariant);
        }

        $rows[] = [$hit, $this->claimSubject($hit)];

        return $rows;
    }

    /**
     * Atomically register the subject for this feature/action.
     *
     * @return bool true when this is the subject's first hit (a new unique)
     */
    protected function claimSubject(Hit $hit): bool
    {
        return $this->subjects()->insertOrIgnore([
            'feature' => $hit->feature,
            'action' => $hit->action,
            'subject' => $hit->subject,
            'variant' => $hit->variant,
            'created_at' => $hit->occurredAt,
        ]) > 0;
    }

    /**
     * Atomically add to a daily counter.
     *
     * UPDATE first: it takes an exclusive row lock straight away and is the
     * only statement once the day's row exists. Only when no row matched do we
     * insert one; if a concurrent batch inserted it first, our insert is
     * ignored and we UPDATE again. (Insert-then-update would take a shared lock
     * and upgrade it, which deadlocks on InnoDB under concurrency.)
     *
     * @param  array{feature: string, variant: string, action: string, date: string, total: int, uniques: int}  $counter
     */
    protected function increment(array $counter): void
    {
        $key = [
            'feature' => $counter['feature'],
            'variant' => $counter['variant'],
            'action' => $counter['action'],
            'date' => $counter['date'],
        ];
        $amounts = ['total' => $counter['total'], 'uniques' => $counter['uniques']];

        if ($this->metrics()->where($key)->incrementEach($amounts) > 0) {
            return;
        }

        if ($this->metrics()->insertOrIgnore([...$key, ...$amounts]) > 0) {
            return;
        }

        $this->metrics()->where($key)->incrementEach($amounts);
    }

    /**
     * All-time unique and total counts per variant for the given actions.
     *
     * Served from the compact daily metrics table, so it stays fast at any
     * traffic volume.
     *
     * @param  list<string>  $actions
     * @return list<VariantCount>
     */
    public function totals(string $feature, array $actions): array
    {
        return array_values($this->metrics()
            ->select('variant', 'action')
            ->selectRaw('SUM(total) as total, SUM(uniques) as uniques')
            ->where('feature', $feature)
            ->whereIn('action', $actions)
            ->groupBy('variant', 'action')
            ->orderBy('variant')
            ->orderBy('action')
            ->get()
            ->map(fn (object $row) => new VariantCount(
                variant: (string) $row->variant,
                action: (string) $row->action,
                uniques: (int) $row->uniques,
                total: (int) $row->total,
            ))
            ->all());
    }

    /**
     * Cohort counts: subjects first exposed between $from and $to (inclusive
     * whole days), and how many of *those* subjects ever performed $action.
     *
     * Date ranges must be cohort based. Counting conversions by the day they
     * happened while counting impressions by the day of first exposure would
     * mix two populations and can report conversion rates above 100%.
     *
     * Reads the subjects table (one row per subject), so it is heavier than
     * totals() and only used when a date range is requested.
     *
     * @return list<VariantCount>
     */
    public function cohort(string $feature, string $action, ?string $from, ?string $to): array
    {
        $exposures = fn () => $this->connection()
            ->table($this->tables['subjects'], 'exposure')
            ->where('exposure.feature', $feature)
            ->where('exposure.action', $this->impressionAction)
            ->when($from !== null, fn (Builder $query) => $query->where('exposure.created_at', '>=', CarbonImmutable::parse((string) $from)->startOfDay()))
            ->when($to !== null, fn (Builder $query) => $query->where('exposure.created_at', '<', CarbonImmutable::parse((string) $to)->addDay()->startOfDay()))
            ->select('exposure.variant')
            ->selectRaw('COUNT(*) as uniques')
            ->groupBy('exposure.variant')
            ->orderBy('exposure.variant');

        $impressions = $exposures()
            ->get()
            ->map(fn (object $row) => new VariantCount((string) $row->variant, $this->impressionAction, (int) $row->uniques, null));

        $conversions = $exposures()
            ->join($this->tables['subjects'].' as conversion', fn (JoinClause $join) => $join
                ->on('conversion.feature', '=', 'exposure.feature')
                ->on('conversion.subject', '=', 'exposure.subject')
                ->where('conversion.action', '=', $action))
            ->get()
            ->map(fn (object $row) => new VariantCount((string) $row->variant, $action, (int) $row->uniques, null));

        return array_values($impressions->concat($conversions)->all());
    }

    /**
     * Every tracked feature with headline totals, for the dashboard index.
     *
     * @return list<FeatureSummary>
     */
    public function features(): array
    {
        return array_values($this->metrics()
            ->select('feature')
            ->selectRaw('COUNT(DISTINCT variant) as variants')
            ->selectRaw('SUM(CASE WHEN action = ? THEN uniques ELSE 0 END) as impressions', [$this->impressionAction])
            ->selectRaw('MAX(date) as last_seen')
            ->groupBy('feature')
            ->orderByDesc('last_seen')
            ->orderBy('feature')
            ->get()
            ->map(fn (object $row) => new FeatureSummary(
                feature: (string) $row->feature,
                variants: (int) $row->variants,
                impressions: (int) $row->impressions,
                lastSeen: (string) $row->last_seen,
            ))
            ->all());
    }

    /**
     * Conversion actions recorded for a feature (everything but impressions).
     *
     * @return list<string>
     */
    public function actions(string $feature): array
    {
        return array_values($this->metrics()
            ->where('feature', $feature)
            ->where('action', '!=', $this->impressionAction)
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->map(fn (mixed $action) => (string) $action)
            ->all());
    }

    /**
     * Delete all analytics for a feature (e.g. after the experiment ships).
     */
    public function forget(string $feature): int
    {
        return $this->connection()->transaction(fn () => $this->metrics()->where('feature', $feature)->delete()
            + $this->subjects()->where('feature', $feature)->delete());
    }

    public function connection(): Connection
    {
        return $this->db->connection($this->connection);
    }

    protected function subjects(): Builder
    {
        return $this->connection()->table($this->tables['subjects']);
    }

    protected function metrics(): Builder
    {
        return $this->connection()->table($this->tables['metrics']);
    }
}
