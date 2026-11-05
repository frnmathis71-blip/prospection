<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\Email;
use App\Models\InboundMessage;
use App\Models\SuppressionEntry;
use Illuminate\Support\Str;

/**
 * Export des données d'une personne (mission M12, droit d'accès RGPD) : tout ce que l'outil
 * détient sur une adresse email, avec la source et la date de collecte de chaque donnée.
 */
class PersonalDataExporter
{
    /**
     * @return array<string, mixed>
     */
    public function export(string $email): array
    {
        $email = mb_strtolower(trim($email));
        $domain = Str::afterLast($email, '@');
        $contacts = Contact::query()->where('email', $email)->with(['company.website', 'company.search'])->get();

        return [
            'adresse' => $email,
            'exporte_le' => now()->toIso8601String(),
            'responsable_du_traitement' => array_filter([
                'nom' => config('prospection.sender.name'),
                'entreprise' => config('prospection.sender.company'),
                'adresse' => config('prospection.sender.address'),
                'siret' => config('prospection.sender.siret'),
                'confidentialite' => config('prospection.sender.privacy_url'),
            ]),
            'finalite' => 'Prospection commerciale B2B : présentation de services de création de sites internet.',
            'duree_de_conservation' => config('prospection.emails.retention_years').' ans sans réponse, puis suppression automatique.',
            'adresses' => $contacts->map(fn (Contact $contact) => [
                'email' => $contact->email,
                'type' => $contact->type->getLabel(),
                'source' => $contact->source->getLabel(),
                'page_source' => $contact->source_url,
                'collectee_le' => $contact->collected_at?->toIso8601String(),
                'verification' => $contact->verification->getLabel(),
                'verifiee_le' => $contact->verified_at?->toIso8601String(),
            ])->values()->all(),
            'entreprises' => $contacts->pluck('company')->filter()->unique('id')->map(fn ($company) => [
                'nom' => $company->name,
                'siren' => $company->siren,
                'code_naf' => $company->naf_code,
                'adresse' => trim($company->address.' '.$company->postal_code.' '.$company->city),
                'telephone' => $company->phone,
                'tranche_effectif' => $company->headcount_range,
                'date_creation' => $company->founded_on?->toDateString(),
                'statut' => $company->status->getLabel(),
                'source' => $company->source?->getLabel(),
                'recherche_d_origine' => $company->search?->name,
                'ajoutee_le' => $company->created_at?->toIso8601String(),
                'site' => $company->website ? [
                    'adresse' => $company->website->url,
                    'etat' => $company->website->state?->getLabel(),
                    'problemes_detectes' => $company->website->problemLabels(),
                    'analyse_le' => $company->website->analyzed_at?->toIso8601String(),
                ] : null,
                'historique' => Activity::query()->where('company_id', $company->id)->orderBy('occurred_at')->get()
                    ->map(fn (Activity $activity) => [
                        'date' => $activity->occurred_at?->toIso8601String(),
                        'type' => $activity->type->getLabel(),
                        'contenu' => $activity->content,
                    ])->all(),
            ])->values()->all(),
            'emails_envoyes' => Email::query()
                ->whereIn('contact_id', $contacts->pluck('id'))
                ->with('campaign')
                ->orderBy('id')
                ->get()
                ->map(fn (Email $sent) => [
                    'objet' => $sent->subject,
                    'campagne' => $sent->campaign?->name,
                    'statut' => $sent->status->getLabel(),
                    'envoye_le' => $sent->sent_at?->toIso8601String(),
                ])->all(),
            'messages_recus' => InboundMessage::query()->where('from', $email)->orderBy('id')->get()
                ->map(fn (InboundMessage $message) => [
                    'objet' => $message->subject,
                    'nature' => $message->kind,
                    'recu_le' => $message->received_at?->toIso8601String(),
                ])->all(),
            'liste_d_opposition' => SuppressionEntry::query()
                ->where('email', $email)
                ->orWhere('domain', $domain)
                ->get()
                ->map(fn (SuppressionEntry $entry) => [
                    'valeur' => $entry->email ?? '@'.$entry->domain,
                    'raison' => $entry->reason?->getLabel(),
                    'depuis' => $entry->created_at?->toIso8601String(),
                ])->all(),
        ];
    }
}
