<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\CompanyScorer;
use App\Support\WorkerHeartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recalcule le score de toutes les entreprises (après un changement de poids, M6).
 */
class RecalculateScores implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function handle(CompanyScorer $scorer): void
    {
        Company::query()->with(['website', 'contacts'])->chunkById(200, function ($companies) use ($scorer) {
            WorkerHeartbeat::beat();
            $companies->each(fn (Company $company) => $scorer->refresh($company));
        });
    }
}
