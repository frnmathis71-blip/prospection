<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Extraction du domaine propre à une entreprise, pour le dédoublonnage.
 *
 * Les réseaux sociaux, annuaires et messageries grand public ne sont pas
 * des domaines d'entreprise : deux entreprises sur facebook.com ou gmail.com
 * ne sont pas la même entreprise.
 */
class Domain
{
    /** Hébergeurs partagés : jamais utilisés comme identité d'une entreprise. */
    public const SHARED_HOSTS = [
        'facebook.com', 'fb.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'tiktok.com',
        'youtube.com', 'google.com', 'goo.gl', 'g.page', 'business.site', 'sites.google.com',
        'pagesjaunes.fr', 'mappy.com', 'tripadvisor.fr', 'tripadvisor.com', 'yelp.fr', 'linktr.ee',
        'wixsite.com', 'jimdofree.com', 'jimdo.com', 'e-monsite.com', 'over-blog.com', 'webnode.fr',
    ];

    /** Réseaux sociaux : une entreprise qui n'a que ça n'a pas de site (état « Réseau social seul »). */
    public const SOCIAL_HOSTS = [
        'facebook.com', 'fb.com', 'fb.me', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'tiktok.com',
        'youtube.com', 'pinterest.com', 'pinterest.fr',
    ];

    /**
     * Annuaires, fiches Google et mini-sites gratuits (état « Mini-site d'annuaire ») :
     * l'entreprise n'a pas de vrai site à elle.
     */
    public const DIRECTORY_HOSTS = [
        'pagesjaunes.fr', 'mappy.com', 'tripadvisor.fr', 'tripadvisor.com', 'yelp.fr', 'yelp.com', 'linktr.ee',
        'google.com', 'goo.gl', 'g.page', 'business.site', 'sites.google.com', 'solocal.com',
        'wixsite.com', 'jimdofree.com', 'jimdosite.com', 'e-monsite.com', 'over-blog.com', 'webnode.fr',
        'webnode.page', 'pagesperso-orange.fr', 'monsite-orange.fr', 'free.fr', 'blogspot.com', 'wordpress.com',
    ];

    /**
     * « social », « directory » (annuaire ou mini-site gratuit) ou null pour un site propre.
     */
    public static function kind(string $host): ?string
    {
        $matches = fn (array $list) => collect($list)->contains(fn (string $shared) => $host === $shared || str_ends_with($host, '.'.$shared));

        return match (true) {
            $matches(self::SOCIAL_HOSTS) => 'social',
            $matches(self::DIRECTORY_HOSTS) => 'directory',
            default => null,
        };
    }

    /** Messageries grand public : une adresse chez elles est personnelle (cf. cahier des charges). */
    public const PERSONAL_MAILBOXES = [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.fr', 'outlook.com', 'outlook.fr', 'live.com',
        'live.fr', 'msn.com', 'yahoo.com', 'yahoo.fr', 'ymail.com', 'icloud.com', 'me.com', 'aol.com', 'aol.fr',
        'orange.fr', 'wanadoo.fr', 'free.fr', 'sfr.fr', 'neuf.fr', 'laposte.net', 'bbox.fr', 'numericable.fr',
        'club-internet.fr', 'gmx.fr', 'gmx.com', 'protonmail.com', 'proton.me',
    ];

    /** Adresses jetables (M7) : jamais une adresse de contact d'entreprise, marquées invalides. */
    public const DISPOSABLE_MAILBOXES = [
        'yopmail.com', 'yopmail.fr', 'mailinator.com', 'guerrillamail.com', 'guerrillamail.info', 'sharklasers.com',
        '10minutemail.com', 'temp-mail.org', 'tempmail.com', 'trashmail.com', 'trashmail.fr', 'jetable.org',
        'getnada.com', 'maildrop.cc', 'dispostable.com', 'throwawaymail.com', 'mailnesia.com', 'spambox.us',
    ];

    public static function isDisposable(string $domain): bool
    {
        return in_array(mb_strtolower($domain), self::DISPOSABLE_MAILBOXES, true);
    }

    /**
     * « https://www.Plombier-Martin.fr/contact » → « plombier-martin.fr ».
     * Renvoie null pour une adresse vide, invalide ou un hébergeur partagé.
     */
    public static function fromUrl(?string $url): ?string
    {
        $host = self::host($url);

        return $host === null || self::isShared($host) ? null : $host;
    }

    /**
     * Domaine d'une adresse email, sauf messagerie grand public.
     */
    public static function fromEmail(?string $email): ?string
    {
        if (blank($email) || ! str_contains($email, '@')) {
            return null;
        }

        $domain = mb_strtolower(trim(Str::afterLast($email, '@')));

        return $domain === '' || self::isPersonalMailbox($domain) || self::isShared($domain) ? null : $domain;
    }

    /**
     * Hôte en minuscules sans « www. », y compris pour un hébergeur partagé.
     */
    public static function host(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $url = trim($url);

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'http://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! str_contains($host, '.')) {
            return null;
        }

        return preg_replace('/^www\./', '', mb_strtolower($host));
    }

    public static function isPersonalMailbox(string $domain): bool
    {
        return in_array(mb_strtolower($domain), self::PERSONAL_MAILBOXES, true);
    }

    public static function isShared(string $host): bool
    {
        foreach (self::SHARED_HOSTS as $shared) {
            if ($host === $shared || str_ends_with($host, '.'.$shared)) {
                return true;
            }
        }

        return false;
    }
}
