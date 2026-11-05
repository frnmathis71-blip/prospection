<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Mots distinctifs d'un nom d'entreprise, pour rapprocher deux sources
 * (« SARL PLOMBERIE MARTIN » ↔ « Plomberie Martin ») sans faux positif sur
 * les mots génériques (« plomberie », « boulangerie », « domaine »…).
 */
class CompanyName
{
    /** Formes juridiques (dont agricoles : EARL, GAEC, SCEA…) et civilités : jamais dans un nom de domaine. */
    private const LEGAL_FORMS = [
        'sarl', 'sas', 'sasu', 'eurl', 'sa', 'sci', 'snc', 'earl', 'gaec', 'scea', 'scp', 'selarl', 'selas', 'scm',
        'sica', 'cuma', 'ei', 'eirl', 'micro', 'ets', 'etablissements', 'etablissement', 'entreprise', 'societe',
        'groupe', 'cie', 'monsieur', 'madame', 'mme', 'mr', 'm',
    ];

    /** Mots vides : ignorés pour comparer deux noms, mais souvent présents dans les domaines (domainedesgrandspres.fr). */
    private const ARTICLES = [
        'et', 'fils', 'freres', 'les', 'des', 'de', 'du', 'la', 'le', 'l', 'd', 'au', 'aux', 'chez', 'en', 'sur', 'a',
    ];

    private const STOP_WORDS = [...self::LEGAL_FORMS, ...self::ARTICLES];

    /** Mots de métier : utiles pour un domaine, mais jamais suffisants seuls pour un rapprochement. */
    private const GENERIC_WORDS = [
        'plomberie', 'plombier', 'chauffage', 'electricite', 'electricien', 'menuiserie', 'menuisier', 'peinture',
        'maconnerie', 'macon', 'couverture', 'charpente', 'renovation', 'batiment', 'construction', 'travaux',
        'boulangerie', 'patisserie', 'boucherie', 'charcuterie', 'traiteur', 'restaurant', 'bar', 'cafe', 'brasserie',
        'pizzeria', 'snack', 'hotel', 'gite', 'gites', 'chambres', 'hotes', 'domaine', 'chateau', 'ferme', 'garage',
        'coiffure', 'coiffeur', 'salon', 'beaute', 'institut', 'esthetique', 'cabinet', 'pharmacie', 'fleurs',
        'fleuriste', 'boutique', 'magasin', 'atelier', 'agence', 'immobilier', 'services', 'service', 'conseil',
        // Viticulture et fermes.
        'vignoble', 'vignobles', 'vigneron', 'vignerons', 'viticulteur', 'viticole', 'vins', 'vin', 'cave', 'caves',
        'cellier', 'clos', 'cuvee', 'cremant', 'negoce', 'exploitation', 'agricole', 'producteur', 'producteurs',
        'elevage', 'eleveur', 'maraicher', 'maraichage', 'fromagerie', 'fromages', 'chevrerie', 'rucher', 'miel',
        'jardins', 'jardin', 'vergers', 'verger', 'bio', 'fermier', 'fermiere',
    ];

    /**
     * Mots du nom, en minuscules, sans accents ni formes juridiques.
     *
     * @return list<string>
     */
    public static function words(string $name): array
    {
        $ascii = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return array_values(array_filter(
            explode(' ', $ascii),
            fn (string $word) => $word !== '' && ! in_array($word, self::STOP_WORDS, true),
        ));
    }

    /**
     * Mots du nom en gardant les articles (« domaine des grands pres »), sans forme juridique.
     *
     * @return list<string>
     */
    public static function wordsWithArticles(string $name): array
    {
        $ascii = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return array_values(array_filter(
            explode(' ', $ascii),
            fn (string $word) => $word !== '' && ! in_array($word, self::LEGAL_FORMS, true),
        ));
    }

    /**
     * Mots qui identifient vraiment l'entreprise (4 lettres au moins, hors métier).
     *
     * @return list<string>
     */
    public static function distinctiveWords(string $name): array
    {
        return array_values(array_filter(
            self::words($name),
            fn (string $word) => strlen($word) >= 4 && ! in_array($word, self::GENERIC_WORDS, true) && ! ctype_digit($word),
        ));
    }

    /**
     * Nombre de mots distinctifs communs aux deux noms (0 = aucun rapprochement possible).
     */
    public static function similarity(string $a, string $b): int
    {
        return count(array_intersect(self::distinctiveWords($a), self::words($b)));
    }

    /**
     * Vrai si un texte (page web) contient au moins un mot distinctif du nom.
     */
    public static function appearsIn(string $name, string $text): bool
    {
        $haystack = ' '.Str::of(Str::ascii($text))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->toString().' ';

        foreach (self::distinctiveWords($name) as $word) {
            if (str_contains($haystack, ' '.$word.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nom présentable dans un email (M8) : « SARL PLOMBERIE MARTIN » → « Plomberie Martin ».
     * Les noms déjà écrits en casse mixte (« Crêp'80 ») sont gardés tels quels, sans forme juridique.
     */
    public static function display(string $name): string
    {
        $name = Str::squish($name);
        $legal = '(?:sarl|sasu|sas|eurl|sci|snc|earl|gaec|scea|scp|selarl|selas|scm|sica|eirl|ei|sa|monsieur|madame)';
        $stripped = trim((string) preg_replace(["/^{$legal}\.?\s+/iu", "/\s+\(?{$legal}\)?\.?$/iu"], '', $name));
        $name = $stripped !== '' ? $stripped : $name;

        return self::titleIfUppercase($name);
    }

    /**
     * « CHALON-SUR-SAONE » → « Chalon-sur-Saone » ; un texte en casse mixte reste inchangé.
     */
    public static function titleIfUppercase(string $text): string
    {
        if ($text !== mb_strtoupper($text)) {
            return $text;
        }

        $title = mb_convert_case(mb_strtolower($text), MB_CASE_TITLE);

        // Articles en minuscules à l'intérieur du nom (« Domaine Des Pres » → « Domaine des Pres »).
        return preg_replace_callback(
            '/(?<=[\s-])(De|Du|Des|La|Le|Les|Et|Sur|Sous|En|Aux|Au)(?=[\s-])/u',
            fn (array $match) => mb_strtolower($match[1]),
            $title,
        );
    }
}
