<?php

namespace Tests\Fakes;

use App\Services\DnsResolver;

/**
 * DNS simulé pour les tests : aucune requête DNS réelle. Par défaut, aucun domaine n'héberge
 * de site et tous reçoivent des emails ; chaque test peut changer ces listes.
 */
class FakeDnsResolver extends DnsResolver
{
    /** @var list<string> domaines qui ont une adresse (A/AAAA) */
    public array $withAddress = [];

    /** @var list<string> domaines SANS serveur MX */
    public array $withoutMailServer = [];

    /** @var list<string> domaines interrogés pour le MX */
    public array $mxLookups = [];

    public function hasAddress(string $domain): bool
    {
        return in_array($domain, $this->withAddress, true);
    }

    public function hasMailServer(string $domain): bool
    {
        $this->mxLookups[] = $domain;

        return ! in_array($domain, $this->withoutMailServer, true);
    }
}
