<?php

namespace App\Services;

use App\Services\Inbox\Mailbox;
use App\Support\TwoFactor;

/**
 * Contrôles avant la mise en production (mission M12) : `php artisan app:production-check`.
 * Ne lit que la configuration ; aucun secret n'est affiché.
 */
class ProductionCheck
{
    /**
     * @return list<string> points à corriger (vide = prêt)
     */
    public function problems(): array
    {
        $problems = [];
        $check = function (bool $ok, string $problem) use (&$problems) {
            if (! $ok) {
                $problems[] = $problem;
            }
        };

        $check(app()->isProduction(), 'APP_ENV doit valoir « production ».');
        $check(! config('app.debug'), 'APP_DEBUG doit valoir false (sinon les erreurs affichent des données).');
        $check(filled(config('app.key')), 'APP_KEY manquante : php artisan key:generate.');
        $check(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL doit commencer par https:// (liens de désinscription des emails).');
        $check(TwoFactor::isRequired(), 'La double authentification doit être obligatoire.');
        $check(! in_array(config('database.default'), ['sqlite'], true), 'Base SQLite : utiliser MySQL ou PostgreSQL en production.');
        $check(config('queue.default') === 'redis', 'QUEUE_CONNECTION doit valoir « redis » (Horizon).');
        $check(! in_array(config('mail.default'), ['log', 'array'], true), 'MAIL_MAILER ne doit pas être « log » : configurer le SMTP du domaine d’envoi dédié.');
        $check(! str_ends_with((string) config('mail.from.address'), '.example'), 'MAIL_FROM_ADDRESS : adresse du domaine d’envoi dédié.');
        $check(! str_ends_with((string) config('mail.from.address'), '@codenyr.fr'), 'MAIL_FROM_ADDRESS ne doit pas utiliser codenyr.fr (domaine d’envoi dédié obligatoire).');

        foreach (['name' => 'SENDER_NAME', 'address' => 'SENDER_ADDRESS', 'siret' => 'SENDER_SIRET'] as $field => $env) {
            $check(filled(config("prospection.sender.{$field}")), "{$env} manquant : identité de l’expéditeur obligatoire dans chaque email.");
        }

        $check(app(Mailbox::class)->isConfigured(), 'IMAP_* manquant : sans lecture de la boîte, les réponses et rebonds ne sont pas détectés.');
        $check(filled(config('backup.backup.password')), 'BACKUP_ARCHIVE_PASSWORD manquant : les sauvegardes contiennent des données personnelles.');
        $check(config('backup.backup.destination.disks') !== [], 'BACKUP_DISKS vide : aucune sauvegarde.');
        $check((int) config('prospection.sending.daily_cap') <= 30, 'SENDING_DAILY_CAP élevé : rester à ~10 par jour pendant le préchauffage, puis monter progressivement.');

        return $problems;
    }
}
