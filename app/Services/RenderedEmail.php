<?php

namespace App\Services;

/**
 * Email de prospection rendu pour un prospect (mission M8) : objet, HTML, texte brut, et ce
 * qui empêche l'envoi (variable vide sans valeur de secours, identité incomplète…).
 */
final class RenderedEmail
{
    /**
     * @param  list<string>  $blocking  raisons qui interdisent l'envoi
     * @param  list<string>  $warnings  conseils de rédaction (longueur, liens…)
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly string $ctaUrl,
        public readonly ?string $unsubscribeUrl,
        public readonly int $wordCount,
        public readonly array $blocking = [],
        public readonly array $warnings = [],
    ) {}

    public function canBeSent(): bool
    {
        return $this->blocking === [] && $this->unsubscribeUrl !== null;
    }
}
