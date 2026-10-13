<?php

namespace CaturSeptian\PennantAnalytics;

use BackedEnum;
use CaturSeptian\PennantAnalytics\Analytics\ExperimentReport;
use CaturSeptian\PennantAnalytics\Exceptions\InvalidTrackingName;
use CaturSeptian\PennantAnalytics\Jobs\PersistHits;
use CaturSeptian\PennantAnalytics\Storage\MetricsRepository;
use CaturSeptian\PennantAnalytics\Support\AnalyticsConfig;
use CaturSeptian\PennantAnalytics\Support\Hit;
use CaturSeptian\PennantAnalytics\Support\PendingScopedTracking;
use CaturSeptian\PennantAnalytics\Support\PennantResolver;
use CaturSeptian\PennantAnalytics\Support\Persistence;
use CaturSeptian\PennantAnalytics\Support\Variant;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Pennant\Events\FeatureRetrieved;
use Laravel\Pennant\Feature;
use Throwable;
use UnitEnum;

/**
 * Tracking engine: resolves *who* is being tracked and *which variant* they
 * see, buffers the resulting hits, and persists them out of the hot path.
 *
 * Request-time cost is one in-memory Pennant lookup (Pennant caches values per
 * request) plus an array append. All I/O happens in flush(), which runs after
 * the response is sent ("deferred"), on a queue ("queue"), or inline ("sync").
 *
 * Error policy:
 *  - Programmer errors (a name that does not fit the schema) throw
 *    InvalidTrackingName from the call site, so they surface in
 *    development instead of silently losing data.
 *  - Runtime failures (database down, Pennant store unreachable) are passed
 *    to report() and swallowed: analytics must never break a checkout.
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

    /**
     * Canonical names of the auto-impression allow-list, resolved lazily.
     *
     * @var array<string, true>|null
     */
    protected ?array $autoImpressionNames = null;

    /** @var (Closure(?Request): (string|int|null))|null */
    protected ?Closure $guestResolver = null;

    public function __construct(
        protected Container $container,
        protected MetricsRepository $repository,
        protected PennantResolver $pennant,
        protected AnalyticsConfig $config,
    ) {}

    /**
     * Record that the current subject was exposed to a feature's variant.
     *
     * Usually unnecessary when auto impressions are enabled, or when you use
     * the @trackImpression directive / pennant.impression middleware.
     *
     * @throws InvalidTrackingName when the feature name does not fit the schema
     */
    public function impression(string|BackedEnum|UnitEnum $feature, mixed $scope = null): void
    {
        $this->record($feature, $this->config->impressionAction, $scope);
    }

    /**
     * Record a conversion action for the current subject.
     *
     *     PennantAnalytics::track('new-checkout-ui', 'completed-purchase');
     *
     * @throws InvalidTrackingName when the feature or action name does not fit the schema
     */
    public function track(string|BackedEnum|UnitEnum $feature, string $action, mixed $scope = null): void
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
     * Customize how guests (null Pennant scope) are identified. Return null to
     * fall back to the default (session UUID, then IP + user agent).
     *
     * @param  Closure(?Request): (string|int|null)  $resolver
     */
    public function resolveGuestUsing(Closure $resolver): static
    {
        $this->guestResolver = $resolver;

        return $this;
    }

    /**
     * Build the conversion report for a feature.
     *
     * Without dates: all-time counts. With dates: the cohort of subjects first
     * exposed in [$from, $to] (inclusive, "Y-m-d"), and every conversion those
     * subjects made, whenever it happened.
     */
    public function report(string|BackedEnum|UnitEnum $feature, string $action, ?string $from = null, ?string $to = null): ExperimentReport
    {
        $name = $this->pennant->name($feature);

        $counts = $from === null && $to === null
            ? $this->repository->totals($name, [$this->config->impressionAction, $action])
            : $this->repository->cohort($name, $action, $from, $to);

        return ExperimentReport::build($name, $action, $counts, $this->config);
    }

    /**
     * Persist everything buffered so far. Never throws (see error policy).
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
            if ($this->config->persistence === Persistence::Queue) {
                PersistHits::dispatch(array_map(fn (Hit $hit) => $hit->toArray(), $hits))
                    ->onConnection($this->config->queueConnection)
                    ->onQueue($this->config->queueName);

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

    public function impressionAction(): string
    {
        return $this->config->impressionAction;
    }

    /**
     * Listener for Pennant's FeatureRetrieved event.
     *
     * Events caused by our own lookups are handed to the resolver; every other
     * event is an application check and may become an automatic impression,
     * using the value Pennant just returned (no second lookup).
     */
    public function handleFeatureRetrieved(FeatureRetrieved $event): void
    {
        if ($this->pennant->capture($event) || ! $this->shouldAutoTrack($event->feature)) {
            return;
        }

        $this->guard(fn () => $this->push(
            $event->feature,
            Variant::label($event->value),
            $this->config->impressionAction,
            $this->subjectFor($event->scope),
        ));
    }

    protected function record(string|BackedEnum|UnitEnum $feature, string $action, mixed $scope): void
    {
        if (! $this->config->enabled) {
            return;
        }

        $this->assertFits('Action', $action, AnalyticsConfig::MAX_ACTION_LENGTH);

        $this->guard(function () use ($feature, $action, $scope) {
            $resolved = $this->pennant->resolve($feature, $scope);

            $this->assertFits('Feature', $resolved->name, AnalyticsConfig::MAX_FEATURE_LENGTH);

            $this->push($resolved->name, Variant::label($resolved->value), $action, $this->subjectFor($resolved->scope));
        });
    }

    protected function push(string $feature, string $variant, string $action, string $subject): void
    {
        $isImpression = $action === $this->config->impressionAction;

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
            occurredAt: Carbon::now()->format('Y-m-d H:i:s'),
            isImpression: $isImpression,
        );

        if ($this->config->persistence === Persistence::Sync) {
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

    /**
     * Identify a guest, most stable source first:
     *  1. the custom resolver, if one is registered and returns non-null
     *  2. a UUID kept in the session (web routes)
     *  3. IP + user agent (stateless routes, e.g. APIs). Users behind one NAT
     *     with the same browser collapse into one subject, and a user whose IP
     *     changes becomes a new subject; register a resolver for APIs.
     *  4. "anonymous" outside HTTP (console, queue) with no scope.
     */
    protected function guestIdentifier(): string
    {
        $request = $this->container->bound('request') ? $this->container->make('request') : null;
        $request = $request instanceof Request ? $request : null;

        if ($this->guestResolver !== null && ($custom = ($this->guestResolver)($request)) !== null) {
            return 'custom|'.$custom;
        }

        if ($request?->hasSession()) {
            $session = $request->session();

            if (! is_string($session->get('pennant_analytics_guest'))) {
                $session->put('pennant_analytics_guest', (string) Str::uuid());
            }

            return 'session|'.$session->get('pennant_analytics_guest');
        }

        if ($request?->ip() !== null) {
            return 'fingerprint|'.$request->ip().'|'.$request->userAgent();
        }

        return 'anonymous';
    }

    protected function shouldAutoTrack(string $feature): bool
    {
        if (! $this->config->enabled || ! $this->config->autoImpressions) {
            return false;
        }

        if ($this->config->autoImpressionFeatures === []) {
            return true;
        }

        // Event names are canonical, so the allow-list (which may contain
        // class names) is canonicalized once through Pennant as well.
        $this->autoImpressionNames ??= array_fill_keys(
            array_map(fn (string $name) => $this->pennant->name($name), $this->config->autoImpressionFeatures),
            true,
        );

        return isset($this->autoImpressionNames[$feature]);
    }

    protected function assertFits(string $kind, string $value, int $max): void
    {
        if ($value === '' || mb_strlen($value) > $max) {
            throw InvalidTrackingName::make($kind, $value, $max);
        }
    }

    protected function guard(Closure $callback): void
    {
        try {
            $callback();
        } catch (InvalidTrackingName $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
        }
    }
}
