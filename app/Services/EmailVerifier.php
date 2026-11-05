<?php

namespace App\Services;

use App\Enums\ContactVerification;
use App\Support\Domain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Vérification gratuite d'une adresse (mission M7) : syntaxe, adresse jetable, puis
 * enregistrement MX du domaine. Pas de vérificateur payant ni de sondage SMTP : les rebonds
 * (M10) complètent le contrôle.
 */
class EmailVerifier
{
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * @return array{status: ContactVerification, reason: ?string}
     */
    public function verify(string $email): array
    {
        $email = mb_strtolower(trim($email));
        $domain = Str::afterLast($email, '@');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! str_contains($domain, '.')) {
            return ['status' => ContactVerification::Invalid, 'reason' => 'Adresse mal formée.'];
        }

        if (Domain::isDisposable($domain)) {
            return ['status' => ContactVerification::Invalid, 'reason' => 'Adresse jetable.'];
        }

        // Un même domaine est vérifié une fois par jour au plus.
        $hasMx = Cache::remember("email-mx:{$domain}", now()->addDay(), fn () => $this->dns->hasMailServer($domain));

        return $hasMx
            ? ['status' => ContactVerification::Valid, 'reason' => null]
            : ['status' => ContactVerification::Invalid, 'reason' => "Le domaine {$domain} ne reçoit pas d’emails (aucun serveur MX)."];
    }
}
