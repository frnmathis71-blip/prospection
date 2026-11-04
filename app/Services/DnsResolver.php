<?php

namespace App\Services;

/**
 * Résolution DNS, isolée pour pouvoir être simulée dans les tests (aucun appel réseau réel).
 */
class DnsResolver
{
    /**
     * Vrai si le domaine a une adresse (A ou AAAA), donc peut héberger un site.
     */
    public function hasAddress(string $domain): bool
    {
        return checkdnsrr($domain.'.', 'A') || checkdnsrr($domain.'.', 'AAAA');
    }

    /**
     * Vrai si le domaine peut recevoir des emails (MX), utilisé à partir de la mission M7.
     */
    public function hasMailServer(string $domain): bool
    {
        return checkdnsrr($domain.'.', 'MX');
    }
}
