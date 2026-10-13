<?php

namespace CaturSeptian\PennantAnalytics\Commands;

use CaturSeptian\PennantAnalytics\Storage\MetricsRepository;
use Illuminate\Console\Command;

class ForgetCommand extends Command
{
    protected $signature = 'pennant-analytics:forget {feature : The Pennant feature name} {--force : Skip confirmation}';

    protected $description = 'Delete all analytics recorded for a feature';

    public function handle(MetricsRepository $repository): int
    {
        $feature = $this->argument('feature');

        if (! $this->option('force') && ! $this->confirm("Delete all analytics for [{$feature}]?")) {
            return self::FAILURE;
        }

        $deleted = $repository->forget($feature);

        $this->components->info("Deleted {$deleted} rows for [{$feature}].");

        return self::SUCCESS;
    }
}
