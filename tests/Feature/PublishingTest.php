<?php

use Illuminate\Support\Facades\File;

it('publishes the migration with a fresh date prefix so it runs after Pennant', function () {
    $target = database_path('migrations');
    $before = File::exists($target) ? File::files($target) : [];

    try {
        $this->artisan('vendor:publish', ['--tag' => 'pennant-analytics-migrations'])->assertSuccessful();

        $published = collect(File::files($target))
            ->reject(fn ($file) => in_array($file, $before))
            ->map(fn ($file) => $file->getFilename())
            ->values();

        expect($published)->toHaveCount(1)
            ->and($published[0])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_pennant_analytics_tables\.php$/')
            // Re-dated at publish time, so it sorts after Pennant's own migration.
            ->and($published[0])->toStartWith(now()->format('Y_m_d_'));
    } finally {
        foreach (File::files($target) as $file) {
            if (! in_array($file, $before)) {
                File::delete($file->getPathname());
            }
        }
    }
});
