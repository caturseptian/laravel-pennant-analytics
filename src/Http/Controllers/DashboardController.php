<?php

namespace CaturSeptian\PennantAnalytics\Http\Controllers;

use CaturSeptian\PennantAnalytics\PennantAnalyticsEngine;
use CaturSeptian\PennantAnalytics\Storage\MetricsRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Read-only dashboard. All-time views read the compact daily metrics table;
 * date-range views run one cohort query over the subjects table.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected MetricsRepository $repository,
        protected PennantAnalyticsEngine $engine,
        protected ViewFactory $view,
    ) {}

    public function index(): View
    {
        return $this->view->make('pennant-analytics::index', [
            'features' => $this->repository->features(),
        ]);
    }

    public function show(Request $request, string $feature): View|JsonResponse
    {
        /** @var array{action?: string|null, from?: string|null, to?: string|null} $validated */
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;
        $actions = $this->repository->actions($feature);
        $action = $validated['action'] ?? $actions[0] ?? null;

        $report = $action !== null ? $this->engine->report($feature, $action, $from, $to) : null;

        if ($request->wantsJson()) {
            return new JsonResponse($report?->toArray() ?? ['feature' => $feature, 'variants' => []]);
        }

        return $this->view->make('pennant-analytics::show', [
            'feature' => $feature,
            'actions' => $actions,
            'action' => $action,
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
