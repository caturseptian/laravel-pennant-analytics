<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two narrow tables instead of one ever-growing event log:
 *
 * 1. subjects - one row per (feature, action, subject). The unique index makes
 *    "INSERT OR IGNORE" an atomic de-duplication primitive, so we know whether a
 *    hit is the subject's *first* one without a read-then-write race. It also
 *    remembers the variant a subject was first exposed to (attribution).
 *    Size is bounded by users x experiments x actions, not by page views.
 *
 * 2. metrics - daily counters per (feature, variant, action, date) updated with
 *    an atomic UPSERT ("total = total + n"). The dashboard only ever aggregates
 *    this table, so reports stay fast no matter how much traffic you have.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('pennant-analytics.connection');
    }

    public function up(): void
    {
        Schema::create(config('pennant-analytics.tables.subjects'), function (Blueprint $table) {
            $table->id();
            $table->string('feature', 150);
            $table->string('action', 100);
            $table->char('subject', 32)->comment('xxh128 digest of the serialized Pennant scope or guest id');
            $table->string('variant', 100);
            $table->timestamp('created_at')->comment('time of the first hit; cohort date filters use it');

            $table->unique(['feature', 'action', 'subject'], 'pa_subjects_unique');
            $table->index(['feature', 'action', 'created_at'], 'pa_subjects_cohort_index');
        });

        Schema::create(config('pennant-analytics.tables.metrics'), function (Blueprint $table) {
            $table->id();
            $table->string('feature', 150);
            $table->string('variant', 100);
            $table->string('action', 100);
            $table->date('date');
            $table->unsignedBigInteger('total')->default(0)->comment('every hit');
            $table->unsignedBigInteger('uniques')->default(0)->comment('first hit per subject');

            $table->unique(['feature', 'variant', 'action', 'date'], 'pa_metrics_unique');
            $table->index(['feature', 'date'], 'pa_metrics_feature_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('pennant-analytics.tables.metrics'));
        Schema::dropIfExists(config('pennant-analytics.tables.subjects'));
    }
};
