<?php

namespace CaturSeptian\PennantAnalytics\Http\Middleware;

use CaturSeptian\PennantAnalytics\PennantAnalyticsEngine;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records impressions for whole routes:
 *
 *     Route::get('/checkout', CheckoutController::class)
 *         ->middleware('pennant.impression:new-checkout-ui');
 *
 * Impressions are recorded after the controller ran, and only for successful
 * responses, so failed requests do not inflate the denominator.
 */
class TrackFeatureImpressions
{
    public function __construct(protected PennantAnalyticsEngine $engine) {}

    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $response = $next($request);

        if ($response->isSuccessful()) {
            foreach ($features as $feature) {
                $this->engine->impression($feature);
            }
        }

        return $response;
    }
}
