<?php

namespace App\Services;

use App\Enums\EmailStatus;
use App\Enums\ProspectStatus;
use App\Models\Company;
use App\Models\InboundMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Purge RGPD (mission M12) : les prospects sans réponse dont le dernier contact (ou la
 * collecte, s'ils n'ont jamais été contactés) date de plus de 3 ans sont supprimés, avec
 * leurs adresses, site, emails et historique.
 *
 * La liste d'opposition n'est JAMAIS purgée : une adresse désinscrite le reste, même après
 * la suppression du prospect.
 */
class ProspectPurger
{
    /** Statuts conservés : relation commerciale engagée (réponse, devis, client). */
    public const KEPT_STATUSES = [ProspectStatus::Replied, ProspectStatus::QuoteSent, ProspectStatus::Won];

    /**
     * Prospects à purger à la date donnée.
     *
     * @return Builder<Company>
     */
    public function candidates(?Carbon $now = null): Builder
    {
        $limit = ($now ?? now())->copy()->subYears((int) config('prospection.emails.retention_years', 3));

        return Company::query()
            ->whereNotIn('status', self::KEPT_STATUSES)
            // Jamais répondu.
            ->whereDoesntHave('emails', fn (Builder $query) => $query->where('emails.status', EmailStatus::Replied))
            ->where('created_at', '<', $limit)
            // Aucun email ni aucune note depuis la limite.
            ->whereDoesntHave('emails', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('emails.sent_at', '>=', $limit)
                ->orWhere('emails.created_at', '>=', $limit)))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('emails')
                ->whereColumn('emails.company_id', 'companies.id')
                ->where(fn ($query) => $query->where('emails.sent_at', '>=', $limit)->orWhere('emails.created_at', '>=', $limit)))
            ->whereDoesntHave('activities', fn (Builder $query) => $query->where('occurred_at', '>=', $limit));
    }

    /**
     * Supprime les prospects concernés. Renvoie leur nombre.
     */
    public function purge(?Carbon $now = null): int
    {
        $count = 0;

        $this->candidates($now)->chunkById(200, function ($companies) use (&$count) {
            foreach ($companies as $company) {
                $emails = $company->contacts()->pluck('email');

                // Messages reçus de ces adresses : seuls l'expéditeur et l'objet étaient gardés.
                InboundMessage::query()->whereIn('from', $emails)->delete();

                // Contacts, site, emails et historique partent en cascade.
                $company->delete();
                $count++;
            }
        });

        return $count;
    }
}
