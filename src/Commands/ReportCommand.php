<?php

namespace CaturSeptian\PennantAnalytics\Commands;

use CaturSeptian\PennantAnalytics\Analytics\VariantResult;
use CaturSeptian\PennantAnalytics\PennantAnalyticsEngine;
use CaturSeptian\PennantAnalytics\Storage\MetricsRepository;
use Illuminate\Console\Command;

class ReportCommand extends Command
{
    protected $signature = 'pennant-analytics:report
        {feature : The Pennant feature name}
        {action? : Conversion action (defaults to the first recorded one)}
        {--from= : Start date (Y-m-d)}
        {--to= : End date (Y-m-d)}
        {--json : Output the report as JSON}';

    protected $description = 'Show conversion rates and statistical significance for a feature';

    public function handle(PennantAnalyticsEngine $engine, MetricsRepository $repository): int
    {
        $feature = $this->argument('feature');
        $action = $this->argument('action') ?? $repository->actions($feature)[0] ?? null;
        $from = $this->option('from');
        $to = $this->option('to');

        foreach (['from' => $from, 'to' => $to] as $option => $date) {
            if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                $this->components->error("--{$option} must be a date in Y-m-d format.");

                return self::FAILURE;
            }
        }

        if ($action === null) {
            $this->components->warn("No conversions recorded for [{$feature}] yet.");

            return self::FAILURE;
        }

        $report = $engine->report($feature, $action, $from, $to);

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->info("{$feature} → {$action}");

        $this->table(
            ['Variant', 'Impressions', 'Conversions', 'Rate', 'Lift', 'p-value', 'Result'],
            array_map(fn (VariantResult $v) => [
                $v->variant.($v->isControl ? ' (control)' : ''),
                number_format($v->impressions),
                number_format($v->conversions),
                number_format($v->conversionRate(), 2).'%',
                $v->comparison?->relativeLift !== null ? sprintf('%+.1f%%', $v->comparison->relativeLift) : '—',
                $v->comparison?->formattedPValue() ?? '—',
                $v->comparison?->verdict() ?? 'baseline',
            ], $report->variants),
        );

        $this->newLine();
        $this->line($report->summary());
        $this->line('<fg=gray>Significance threshold: '.$report->thresholdDescription().'</>');

        if ($from !== null || $to !== null) {
            $this->line('<fg=gray>Cohort of subjects first exposed in the date range, with all of their conversions.</>');
        }

        return self::SUCCESS;
    }
}
