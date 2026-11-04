<?php

namespace App\Services;

use App\Enums\ContactType;
use App\Enums\ContactVerification;
use App\Enums\DataSource;
use App\Enums\ProspectStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Support\Domain;
use Illuminate\Support\Str;

/**
 * Enregistre une adresse email trouvée pour une entreprise, avec sa source et sa date de
 * collecte. Une adresse (ou un domaine) de la liste d'opposition est conservée, mais
 * l'entreprise passe en « Ne pas contacter ».
 */
class ContactRecorder
{
    /** Préfixes d'adresses génériques (contact@, info@…). */
    private const GENERIC_PREFIXES = [
        'contact', 'info', 'infos', 'accueil', 'bonjour', 'hello', 'commercial', 'devis', 'secretariat',
        'administration', 'admin', 'direction', 'reservation', 'reservations', 'agence', 'cabinet', 'office',
        'service', 'sav', 'boutique', 'magasin', 'atelier', 'restaurant', 'mairie',
    ];

    /** Statuts déjà bloquants : jamais modifiés ici. */
    private const BLOCKING_STATUSES = [ProspectStatus::Unsubscribed, ProspectStatus::Bounced, ProspectStatus::DoNotContact];

    public static function record(Company $company, string $email, DataSource $source): Contact
    {
        $email = mb_strtolower(trim($email));

        /** @var Contact $contact */
        $contact = $company->contacts()->firstOrCreate(
            ['email' => $email],
            [
                'type' => self::type($email),
                'source' => $source,
                'collected_at' => now(),
                'verification' => ContactVerification::Pending,
            ],
        );

        if ($contact->isSuppressed() && ! in_array($company->status, self::BLOCKING_STATUSES, true)) {
            $company->update(['status' => ProspectStatus::DoNotContact]);
        }

        return $contact;
    }

    /**
     * Générique (contact@), nominative (jean.dupont@) ou personnelle (messagerie grand public).
     */
    public static function type(string $email): ContactType
    {
        $local = Str::before($email, '@');
        $domain = Str::afterLast($email, '@');

        return match (true) {
            Domain::isPersonalMailbox($domain) => ContactType::Personal,
            in_array(preg_replace('/[^a-z]/', '', $local), self::GENERIC_PREFIXES, true) => ContactType::Generic,
            default => ContactType::Nominative,
        };
    }
}
