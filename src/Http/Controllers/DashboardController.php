<?php

namespace CaturSeptian\PennantAnalytics\Http\Controllers;

use CaturSeptian\PennantAnalytics\PennantAnalyticsEngine;
use CaturSeptian\PennantAnalytics\Storage\MetricsRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Read-only dashboard. Every query hits the compact daily metrics table, so
 * page loads are O(variants x actions x days) regardless of traffic volume.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected MetricsRepository $repository,
        protected PennantAnalyticsEngine $engine,
    ) {}

    public function index(): View
    {
        return view('pennant-analytics::index', [
            'features' => $this->repository->features(),
        ]);
    }

    public function show(Request $request, string $feature): View|JsonResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $actions = $this->repository->actions($feature);
        $action = $validated['action'] ?? $actions[0] ?? null;

        $report = $action !== null
            ? $this->engine->report($feature, $action, $validated['from'] ?? null, $validated['to'] ?? null)
            : null;

        if ($request->wantsJson()) {
            return new JsonResponse($report?->toArray() ?? ['feature' => $feature, 'variants' => []]);
        }

        return view('pennant-analytics::show', [
            'feature' => $feature,
            'actions' => $actions,
            'action' => $action,
            'report' => $report,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ]);
    }
}
