<?php

namespace App\Services;

use App\Enums\ContactType;
use App\Enums\ContactVerification;
use App\Enums\EmailStatus;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Email;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Liste ciblée d'une campagne (mission M9) : prospects « À traiter » qui correspondent aux
 * critères et ont une adresse envoyable (vérifiée, hors liste d'opposition, adresse
 * personnelle validée à la main). Une seule adresse par entreprise, la générique d'abord.
 */
class CampaignAudience
{
    /**
     * @return Collection<int, array{company: Company, contact: Contact}>
     */
    public function recipients(Campaign $campaign): Collection
    {
        $criteria = $campaign->criteria ?? [];

        $companies = Company::query()
            ->toProcess()
            ->with(['contacts' => fn ($query) => self::sendable($query)])
            ->whereHas('contacts', fn ($query) => self::sendable($query))
            // Jamais deux fois par la même campagne.
            ->whereNotIn('id', Email::query()->where('campaign_id', $campaign->id)->whereNotNull('company_id')->select('company_id'))
            ->when($campaign->search_id, fn (Builder $query, $id) => $query->where('search_id', $id))
            ->when(filled($criteria['min_score'] ?? null), fn (Builder $query) => $query->where('score', '>=', (int) $criteria['min_score']))
            ->when($criteria['departments'] ?? [], fn (Builder $query, array $departments) => $query->whereIn('department', $departments))
            ->when($criteria['website_states'] ?? [], fn (Builder $query, array $states) => $query->whereHas('website', fn ($query) => $query->whereIn('state', $states)))
            ->when($criteria['sectors'] ?? [], fn (Builder $query, array $sectors) => $query->whereIn('naf_code', collect($sectors)
                ->flatMap(fn (string $sector) => config("prospection.sectors.{$sector}.naf", []))
                ->all()))
            ->orderByDesc('score')
            ->orderBy('id')
            ->when(filled($criteria['limit'] ?? null), fn (Builder $query) => $query->limit((int) $criteria['limit']))
            ->get();

        return $companies
            ->map(fn (Company $company) => ['company' => $company, 'contact' => self::bestContact($company->contacts)])
            ->filter(fn (array $recipient) => $recipient['contact'] !== null)
            ->values();
    }

    /**
     * Met en file le premier email (étape 1 de la séquence) de chaque destinataire.
     * Rien ne part ici : l'envoi se fait ensuite, un par un, par SendNextEmail.
     */
    public function enqueue(Campaign $campaign): int
    {
        $step = $campaign->sequence->steps()->first();

        if ($step === null) {
            return 0;
        }

        $count = 0;

        foreach ($this->recipients($campaign) as ['company' => $company, 'contact' => $contact]) {
            $email = Email::query()->firstOrCreate(
                ['campaign_id' => $campaign->id, 'company_id' => $company->id, 'sequence_step_id' => $step->id],
                ['contact_id' => $contact->id, 'template_id' => $step->template_id, 'status' => EmailStatus::Queued],
            );

            if ($email->wasRecentlyCreated) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Adresse envoyable : vérifiée, pas en attente de revue manuelle.
     * (La liste d'opposition est filtrée en PHP, puis revérifiée au moment de l'envoi.)
     */
    public static function sendable($query)
    {
        return $query->where('verification', ContactVerification::Valid)
            ->where(fn ($query) => $query->where('type', '!=', ContactType::Personal)->orWhereNotNull('reviewed_at'));
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     */
    public static function bestContact(Collection $contacts): ?Contact
    {
        $order = [ContactType::Generic->value => 0, ContactType::Nominative->value => 1, ContactType::Personal->value => 2];

        return $contacts
            ->reject(fn (Contact $contact) => $contact->isSuppressed())
            ->sortBy(fn (Contact $contact) => $order[$contact->type->value] ?? 9)
            ->first();
    }
}
