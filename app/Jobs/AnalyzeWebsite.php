<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\CompanyScorer;
use App\Services\EmailFinder;
use App\Services\WebsiteAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;

/**
 * Analyse le site d'une entreprise (mission M5). Une entreprise sans site reçoit l'état
 * « Aucun site » : chaque entreprise a ainsi un état de site.
 *
 * File « enrichment » (après imports et recherches), débit limité (RateLimiter « analysis »).
 */
class AnalyzeWebsite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public function __construct(public Company $company)
    {
        $this->onQueue(EnrichCompany::QUEUE);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('analysis')];
    }

    public function retryUntil(): Carbon
    {
        return now()->addDay();
    }

    public function handle(WebsiteAnalyzer $analyzer, CompanyScorer $scorer): void
    {
        $company = $this->company->fresh();

        if ($company === null) {
            return;
        }

        $website = $company->website ?? $company->website()->make();

        $analyzer->analyze($website);

        // M7 : un site en ligne est ensuite exploré pour trouver ses emails publiés.
        if (in_array($website->state, EmailFinder::CRAWLABLE_STATES, true)) {
            FindContactEmails::dispatch($company);
        }

        // M6 : le score dépend surtout du site, il est recalculé après chaque analyse.
        $scorer->refresh($company->fresh());
    }
}
