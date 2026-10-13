<?php

namespace CaturSeptian\PennantAnalytics\Storage;

use CaturSeptian\PennantAnalytics\Support\Hit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The only class that talks to the database.
 *
 * Writes are designed to be race-free under concurrency without locks:
 *  - uniqueness is decided by the database ("insert or ignore" on a unique key)
 *  - counters are bumped with an atomic UPSERT ("total = total + n")
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

    /**
     * Persist a batch of hits.
     *
     * @param  iterable<Hit>  $hits
     */
    public function persist(iterable $hits): void
    {
        $connection = $this->connection();

        $connection->transaction(function () use ($hits) {
            /** @var array<string, array{feature: string, variant: string, action: string, date: string, total: int, uniques: int}> $counters */
            $counters = [];

            foreach ($hits as $hit) {
                foreach ($this->expand($hit) as [$resolved, $isNew]) {
                    $key = implode("\0", [$resolved->feature, $resolved->variant, $resolved->action, $resolved->date]);

                    $counters[$key] ??= [
                        'feature' => $resolved->feature,
                        'variant' => $resolved->variant,
                        'action' => $resolved->action,
                        'date' => $resolved->date,
                        'total' => 0,
                        'uniques' => 0,
                    ];

                    $counters[$key]['total']++;
                    $counters[$key]['uniques'] += (int) $isNew;
                }
            }

            foreach ($counters as $counter) {
                $this->increment($counter);
            }
        });
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
            'created_at' => Carbon::now(),
        ]) > 0;
    }

    /**
     * @param  array{feature: string, variant: string, action: string, date: string, total: int, uniques: int}  $counter
     */
    protected function increment(array $counter): void
    {
        $grammar = $this->connection()->getQueryGrammar();
        $table = $this->tables['metrics'];

        $this->metrics()->upsert(
            [$counter],
            ['feature', 'variant', 'action', 'date'],
            [
                // Qualified with the table name so the right-hand side refers to
                // the existing row on MySQL, PostgreSQL and SQLite alike.
                'total' => $this->connection()->raw($grammar->wrap($table.'.total').' + '.(int) $counter['total']),
                'uniques' => $this->connection()->raw($grammar->wrap($table.'.uniques').' + '.(int) $counter['uniques']),
            ],
        );
    }

    /**
     * Aggregate counters per variant and action for one feature.
     *
     * Note: "uniques" are attributed to the day of a subject's first hit, so a
     * date-bounded query counts subjects who *entered* the experiment in range.
     *
     * @param  list<string>|null  $actions
     * @return Collection<int, object{variant: string, action: string, total: int, uniques: int}>
     */
    public function aggregate(string $feature, ?array $actions = null, ?string $from = null, ?string $to = null): Collection
    {
        return $this->metrics()
            ->select('variant', 'action')
            ->selectRaw('SUM(total) as total, SUM(uniques) as uniques')
            ->where('feature', $feature)
            ->when($actions, fn (Builder $query) => $query->whereIn('action', $actions))
            ->when($from, fn (Builder $query) => $query->where('date', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('date', '<=', $to))
            ->groupBy('variant', 'action')
            ->orderBy('variant')
            ->get()
            ->map(function (object $row) {
                $row->total = (int) $row->total;
                $row->uniques = (int) $row->uniques;

                return $row;
            });
    }

    /**
     * Every tracked feature with headline totals, for the dashboard index.
     *
     * @return Collection<int, object{feature: string, variants: int, impressions: int, last_seen: string}>
     */
    public function features(): Collection
    {
        return $this->metrics()
            ->select('feature')
            ->selectRaw('COUNT(DISTINCT variant) as variants')
            ->selectRaw('SUM(CASE WHEN action = ? THEN uniques ELSE 0 END) as impressions', [$this->impressionAction])
            ->selectRaw('MAX(date) as last_seen')
            ->groupBy('feature')
            ->orderByDesc('last_seen')
            ->orderBy('feature')
            ->get();
    }

    /**
     * Conversion actions recorded for a feature (everything but impressions).
     *
     * @return list<string>
     */
    public function actions(string $feature): array
    {
        return $this->metrics()
            ->where('feature', $feature)
            ->where('action', '!=', $this->impressionAction)
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }

    /**
     * Daily unique counts for a feature, for time-series charts.
     *
     * @return Collection<int, object{date: string, variant: string, action: string, uniques: int}>
     */
    public function daily(string $feature, ?string $from = null, ?string $to = null): Collection
    {
        return $this->metrics()
            ->select('date', 'variant', 'action', 'uniques')
            ->where('feature', $feature)
            ->when($from, fn (Builder $query) => $query->where('date', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('date', '<=', $to))
            ->orderBy('date')
            ->get();
    }

    /**
     * Delete all analytics for a feature (e.g. after the experiment ships).
     */
    public function forget(string $feature): int
    {
        return $this->connection()->transaction(fn () => $this->metrics()->where('feature', $feature)->delete()
            + $this->subjects()->where('feature', $feature)->delete());
    }

    public function connection(): ConnectionInterface
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
