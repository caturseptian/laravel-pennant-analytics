<?php

namespace CaturSeptian\PennantAnalytics;

use CaturSeptian\PennantAnalytics\Analytics\ExperimentReport;
use CaturSeptian\PennantAnalytics\Jobs\PersistHits;
use CaturSeptian\PennantAnalytics\Storage\MetricsRepository;
use CaturSeptian\PennantAnalytics\Support\Hit;
use CaturSeptian\PennantAnalytics\Support\PendingScopedTracking;
use CaturSeptian\PennantAnalytics\Support\Variant;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Pennant\Events\FeatureRetrieved;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Tracking engine: resolves *who* is being tracked and *which variant* they
 * see, buffers the resulting hits, and persists them out of the hot path.
 *
 * Request-time cost is one in-memory Pennant lookup (Pennant caches values per
 * request) plus an array append. All I/O happens in flush(), which runs after
 * the response is sent ("deferred"), on a queue ("queue"), or inline ("sync").
 */
class PennantAnalyticsEngine
{
    /** @var list<Hit> */
    protected array $buffer = [];

    /**
     * Impressions already buffered this request, keyed by feature + subject,
     * so a flag checked ten times on one page records one impression.
     *
     * @var array<string, true>
     */
    protected array $seenImpressions = [];

    /** True while *we* are asking Pennant for a value (see captureScope()). */
    protected bool $resolving = false;

    protected bool $scopeCaptured = false;

    protected mixed $capturedScope = null;

    protected ?Closure $subjectResolver = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Container $container,
        protected MetricsRepository $repository,
        protected array $config,
    ) {}

    /**
     * Record that the current subject was exposed to a feature's variant.
     *
     * Usually unnecessary when auto impressions are enabled, or when you use
     * the @trackImpression directive / pennant-analytics middleware.
     */
    public function impression(string $feature, mixed $scope = null): void
    {
        $this->record($feature, $this->impressionAction(), $scope);
    }

    /**
     * Record a conversion action for the current subject.
     *
     *     PennantAnalytics::track('new-checkout-ui', 'completed-purchase');
     */
    public function track(string $feature, string $action, mixed $scope = null): void
    {
        $this->record($feature, $action, $scope);
    }

    /**
     * Track against an explicit Pennant scope (team, tenant, model...).
     *
     *     PennantAnalytics::for($team)->track('new-billing', 'upgraded');
     */
    public function for(mixed $scope): PendingScopedTracking
    {
        return new PendingScopedTracking($this, $scope);
    }

    /**
     * Customize how guests (null Pennant scope) are identified.
     *
     * @param  Closure(?Request): (string|int|null)  $resolver
     */
    public function resolveGuestUsing(Closure $resolver): static
    {
        $this->subjectResolver = $resolver;

        return $this;
    }

    /**
     * Build the conversion report for a feature.
     */
    public function report(string $feature, string $action, ?string $from = null, ?string $to = null): ExperimentReport
    {
        return ExperimentReport::build(
            $feature,
            $action,
            $this->repository->aggregate($feature, [$this->impressionAction(), $action], $from, $to),
            $this->impressionAction(),
            $this->config['statistics'] ?? [],
        );
    }

    /**
     * Persist everything buffered so far. Never throws: analytics must not be
     * able to break the host application, so failures are reported instead.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $hits = $this->buffer;
        $this->buffer = [];
        $this->seenImpressions = [];

        try {
            if ($this->config['persistence'] === 'queue') {
                PersistHits::dispatch(array_map(fn (Hit $hit) => $hit->toArray(), $hits))
                    ->onConnection($this->config['queue']['connection'] ?? null)
                    ->onQueue($this->config['queue']['name'] ?? null);

                return;
            }

            $this->repository->persist($hits);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Hits waiting to be persisted (mainly for tests and debugging).
     *
     * @return list<Hit>
     */
    public function pending(): array
    {
        return $this->buffer;
    }

    /**
     * Listener for Pennant's FeatureRetrieved event.
     *
     * Serves two purposes:
     *  1. While we resolve a variant ourselves, it captures the scope Pennant
     *     actually used, so we honour Feature::resolveScopeUsing() without
     *     touching Pennant internals.
     *  2. Otherwise, it records automatic impressions for configured features
     *     using the value Pennant just returned (no second lookup).
     */
    public function handleFeatureRetrieved(FeatureRetrieved $event): void
    {
        if ($this->resolving) {
            $this->scopeCaptured = true;
            $this->capturedScope = $event->scope;

            return;
        }

        if (! $this->shouldAutoTrack($event->feature)) {
            return;
        }

        $this->guard(fn () => $this->push(
            $event->feature,
            Variant::label($event->value),
            $this->impressionAction(),
            $this->subjectFor($event->scope),
        ));
    }

    protected function record(string $feature, string $action, mixed $scope): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->guard(function () use ($feature, $action, $scope) {
            [$value, $resolvedScope] = $this->resolve($feature, $scope);

            $this->push($feature, Variant::label($value), $action, $this->subjectFor($resolvedScope));
        });
    }

    /**
     * Ask Pennant for the value, capturing the scope it resolved.
     *
     * @return array{0: mixed, 1: mixed}
     */
    protected function resolve(string $feature, mixed $scope): array
    {
        $this->resolving = true;
        $this->scopeCaptured = false;
        $this->capturedScope = null;

        try {
            $value = $scope === null
                ? Feature::value($feature)
                : Feature::for($scope)->value($feature);
        } finally {
            $this->resolving = false;
        }

        return [$value, $this->scopeCaptured ? $this->capturedScope : $scope];
    }

    protected function push(string $feature, string $variant, string $action, string $subject): void
    {
        $isImpression = $action === $this->impressionAction();

        if ($isImpression) {
            $key = $feature."\0".$subject;

            if (isset($this->seenImpressions[$key])) {
                return;
            }

            $this->seenImpressions[$key] = true;
        }

        $this->buffer[] = new Hit(
            feature: $feature,
            variant: $variant,
            action: $action,
            subject: $subject,
            date: Carbon::now()->toDateString(),
            isImpression: $isImpression,
        );

        if ($this->config['persistence'] === 'sync') {
            $this->flush();
        }
    }

    /**
     * Build a fixed-width subject identifier.
     *
     * Non-null scopes use Pennant's own serialization ("App\Models\User|42"),
     * so analytics subjects line up exactly with Pennant's stored values.
     * Null scopes (guests) fall back to a guest identifier. The digest keeps
     * the indexed column compact; it is NOT anonymization for guessable ids.
     */
    protected function subjectFor(mixed $scope): string
    {
        $identifier = $scope === null
            ? 'guest|'.$this->guestIdentifier()
            : Feature::serializeScope($scope);

        return hash('xxh128', $identifier);
    }

    protected function guestIdentifier(): string
    {
        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        if ($this->subjectResolver !== null && ($custom = ($this->subjectResolver)($request)) !== null) {
            return (string) $custom;
        }

        if ($request instanceof Request && $request->hasSession()) {
            $session = $request->session();

            if (! $session->has('pennant_analytics_guest')) {
                $session->put('pennant_analytics_guest', (string) Str::uuid());
            }

            return $session->get('pennant_analytics_guest');
        }

        if ($request instanceof Request && $request->ip() !== null) {
            return 'fp|'.$request->ip().'|'.$request->userAgent();
        }

        return 'anonymous';
    }

    protected function shouldAutoTrack(string $feature): bool
    {
        if (! $this->enabled() || ! ($this->config['auto_impressions']['enabled'] ?? false)) {
            return false;
        }

        $features = $this->config['auto_impressions']['features'] ?? [];

        return $features === [] || in_array($feature, $features, true);
    }

    protected function guard(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function impressionAction(): string
    {
        return $this->config['impression_action'] ?? 'impression';
    }
}
