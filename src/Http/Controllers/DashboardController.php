<?php

namespace CaturSeptian\PennantAnalytics\Http\Controllers;

use CaturSeptian\PennantAnalytics\PennantAnalyticsEngine;
use CaturSeptian\PennantAnalytics\Storage\MetricsRepository;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        protected ValidationFactory $validator,
    ) {}

    public function index(): View
    {
        return $this->view->make('pennant-analytics::index', [
            'features' => $this->repository->features(),
        ]);
    }

    /**
     * Invalid filters never redirect: a GET report page has no sensible "back",
     * and redirecting would silently show some other page's data. Instead the
     * page renders the error next to the unfiltered report (JSON: 422).
     */
    public function show(Request $request, string $feature): View|JsonResponse
    {
        $actions = $this->repository->actions($feature);

        $validator = $this->validator->make($request->query(), [
            'action' => ['nullable', 'string', Rule::in($actions)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'action.in' => 'No ":input" conversions are recorded for this feature.',
        ]);

        if ($validator->fails() && $request->wantsJson()) {
            throw new ValidationException($validator);
        }

        // Keep only the filters that passed; an invalid one is simply not applied.
        $failed = $validator->errors()->messages();
        $filter = fn (string $key): ?string => ! isset($failed[$key]) && is_string($request->query($key)) && $request->query($key) !== ''
            ? $request->query($key)
            : null;

        $from = $filter('from');
        $to = $filter('to');
        $action = $filter('action') ?? $actions[0] ?? null;

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
            'filterErrors' => $validator->errors()->all(),
        ]);
    }
}
