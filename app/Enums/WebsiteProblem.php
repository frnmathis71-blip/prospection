<?php

namespace App\Enums;

/**
 * Problème réellement détecté sur un site (mission M5), avec son poids pour le score (M6).
 *
 * Les libellés décrivent un constat vérifiable, sans exagération : ils seront repris tels
 * quels dans les emails (« vos clients… ») et ne doivent jamais affirmer plus que l'analyse.
 */
enum WebsiteProblem: string
{
    case NoHttps = 'no_https';
    case InvalidCertificate = 'invalid_certificate';
    case NotResponsive = 'not_responsive';
    case SlowMobile = 'slow_mobile';
    case OldCopyright = 'old_copyright';
    case OutdatedCms = 'outdated_cms';
    case OldJquery = 'old_jquery';
    case Flash = 'flash';
    case Frames = 'frames';
    case TableLayout = 'table_layout';
    case NoLegalNotice = 'no_legal_notice';
    case NoContact = 'no_contact';

    /** Poids suggéré par le cahier des charges : fort, moyen ou faible. */
    public function severity(): string
    {
        return match ($this) {
            self::NoHttps, self::InvalidCertificate, self::NotResponsive => 'high',
            self::SlowMobile, self::OldCopyright, self::OutdatedCms, self::OldJquery,
            self::Flash, self::Frames, self::TableLayout => 'medium',
            self::NoLegalNotice, self::NoContact => 'low',
        };
    }

    /**
     * Libellé en clair, complété par les détails de l'analyse (année, version, score…).
     *
     * @param  array<string, mixed>  $details
     */
    public function label(array $details = []): string
    {
        return match ($this) {
            self::NoHttps => 'Le site n’est pas sécurisé (pas de HTTPS)',
            self::InvalidCertificate => 'Le certificat de sécurité du site est expiré ou invalide',
            self::NotResponsive => 'Le site n’est pas adapté à l’affichage sur téléphone',
            self::SlowMobile => 'Le site est lent sur mobile'.(isset($details['pagespeed']) ? " (score PageSpeed {$details['pagespeed']}/100)" : ''),
            self::OldCopyright => 'Le site ne semble plus mis à jour'.(isset($details['copyright_year']) ? " (© {$details['copyright_year']})" : ''),
            self::OutdatedCms => 'Le site utilise une ancienne version de '.($details['cms'] ?? 'son logiciel'),
            self::OldJquery => 'Le site utilise une bibliothèque ancienne'.(isset($details['jquery']) ? " (jQuery {$details['jquery']})" : ''),
            self::Flash => 'Le site utilise Flash, qui n’est plus lu par les navigateurs',
            self::Frames => 'Le site est construit avec des cadres (frames), une technique abandonnée',
            self::TableLayout => 'La mise en page repose sur des tableaux, une technique ancienne',
            self::NoLegalNotice => 'Aucune page de mentions légales n’est visible depuis l’accueil',
            self::NoContact => 'Aucun formulaire ni page de contact n’est visible depuis l’accueil',
        };
    }
}
