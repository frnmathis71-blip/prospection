<?php

namespace App\Services\Inbox;

use Carbon\CarbonInterface;

/**
 * Boîte de réception de l'adresse d'envoi (M10). Simulée dans les tests (aucune connexion réelle).
 */
interface Mailbox
{
    public function isConfigured(): bool;

    /**
     * Sources brutes (RFC 822) des messages reçus depuis cette date.
     *
     * @return iterable<string>
     */
    public function rawMessagesSince(CarbonInterface $since): iterable;
}
