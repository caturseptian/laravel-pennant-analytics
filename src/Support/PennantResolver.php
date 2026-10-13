<?php

namespace CaturSeptian\PennantAnalytics\Support;

use BackedEnum;
use Laravel\Pennant\Events\FeatureRetrieved;
use Laravel\Pennant\Feature;
use UnitEnum;

/**
 * The single place that talks to Pennant.
 *
 * Pennant does not expose the default scope it resolves (the authenticated
 * user, or whatever Feature::resolveScopeUsing() returns) through a public
 * API. It does, however, announce every evaluation through its
 * FeatureRetrieved event, including the resolved scope. resolve() therefore
 * opens a short "capture window": the event listener forwards the event to
 * capture(), and the last event received for the requested feature inside
 * the window is the evaluation we asked for.
 */
class PennantResolver
{
    /** Feature name we are currently resolving, or null outside a window. */
    private ?string $capturing = null;

    private ?FeatureRetrieved $captured = null;

    /**
     * Canonical Pennant name: class based features resolve to their `$name`
     * property, enums to their value, strings stay as they are.
     */
    public function name(string|BackedEnum|UnitEnum $feature): string
    {
        return Feature::name($feature);
    }

    public function resolve(string|BackedEnum|UnitEnum $feature, mixed $scope): ResolvedFeature
    {
        $name = $this->name($feature);

        $this->capturing = $name;
        $this->captured = null;

        try {
            $value = $scope === null
                ? Feature::value($name)
                : Feature::for($scope)->value($name);
        } finally {
            $captured = $this->closeWindow();
        }

        return new ResolvedFeature($name, $value, $captured !== null ? $captured->scope : $scope);
    }

    /**
     * Close the capture window and hand over what the listener captured.
     */
    private function closeWindow(): ?FeatureRetrieved
    {
        $captured = $this->captured;

        $this->capturing = null;
        $this->captured = null;

        return $captured;
    }

    /**
     * Offer an event to the open capture window.
     *
     * @return bool true when the event belonged to our own resolve() call and
     *              must therefore not be treated as an application check
     */
    public function capture(FeatureRetrieved $event): bool
    {
        if ($this->capturing === null) {
            return false;
        }

        if ($event->feature === $this->capturing) {
            $this->captured = $event;
        }

        return true;
    }
}
