<?php

namespace CaturSeptian\PennantAnalytics\Support;

/**
 * When buffered hits are written to the database.
 */
enum Persistence: string
{
    /** After the response has been sent (application terminate). */
    case Deferred = 'deferred';

    /** On a queue worker, one job per request. */
    case Queue = 'queue';

    /** Immediately, inside the tracking call. */
    case Sync = 'sync';
}
