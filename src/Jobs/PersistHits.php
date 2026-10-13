<?php

namespace CaturSeptian\PennantAnalytics\Jobs;

use CaturSeptian\PennantAnalytics\Storage\MetricsRepository;
use CaturSeptian\PennantAnalytics\Support\Hit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Persists one request's worth of hits on a queue worker.
 *
 * Hits are passed as plain arrays: no models, closures or request state are
 * serialized, keeping the payload tiny and safe across deployments.
 */
class PersistHits implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  list<array{feature: string, variant: string, action: string, subject: string, date: string, impression: bool}>  $hits
     */
    public function __construct(public array $hits) {}

    public function handle(MetricsRepository $repository): void
    {
        $repository->persist(array_map(Hit::fromArray(...), $this->hits));
    }
}
