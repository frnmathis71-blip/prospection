<?php

/*
|--------------------------------------------------------------------------
| Prospection : sources de données gratuites et limites de débit
|--------------------------------------------------------------------------
|
| Aucune clé n'est nécessaire. Les limites ci-dessous restent sous celles
| publiées par chaque service (« ne pas marteler les API publiques »).
|
*/

return [

    // Identifie honnêtement l'outil auprès des services et des sites visités.
    'user_agent' => env('PROSPECTION_USER_AGENT', 'CodenyrProspection/1.0 (+https://codenyr.fr)'),

    // https://recherche-entreprises.api.gouv.fr — limite publiée : 7 requêtes/s par IP.
    'recherche_entreprises' => [
        'url' => env('RECHERCHE_ENTREPRISES_URL', 'https://recherche-entreprises.api.gouv.fr'),
        'per_page' => 25, // maximum autorisé par l'API
        'max_pages' => (int) env('RECHERCHE_ENTREPRISES_MAX_PAGES', 40), // 1 000 résultats par lancement
        'requests_per_second' => 4,
        'timeout' => 20,
    ],

    // BODACC (annonces légales) : repère les procédures collectives, absentes de l'API ci-dessus.
    'bodacc' => [
        'url' => env('BODACC_URL', 'https://bodacc-datadila.opendatasoft.com/api/explore/v2.1/catalog/datasets/annonces-commerciales/records'),
        'lookback_years' => 3, // une procédure publiée depuis moins de 3 ans exclut l'entreprise
        'timeout' => 20,
    ],

    // OpenStreetMap via Overpass (données © contributeurs OpenStreetMap, licence ODbL).
    'overpass' => [
        // Serveur public principal. Autres serveurs publics possibles (à tester avant, leur
        // disponibilité varie) : overpass.private.coffee, overpass.kumi.systems.
        'url' => env('OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
        'radius_meters' => 150,
        // Le serveur public peut mettre 30 à 40 s à répondre aux heures chargées.
        'timeout' => (int) env('OVERPASS_TIMEOUT', 60),
    ],

    // Devinette de domaine (nomentreprise.fr / .com) : DNS puis page d'accueil.
    'domain_guess' => [
        'tlds' => ['fr', 'com'],
        'timeout' => 8,
    ],

    // Enrichissements par minute (OpenStreetMap + devinette), pour un usage raisonnable.
    'enrichments_per_minute' => (int) env('PROSPECTION_ENRICHMENTS_PER_MINUTE', 20),

    // Zone cible.
    'departments' => [
        '21' => "21 — Côte-d'Or",
        '58' => '58 — Nièvre',
        '71' => '71 — Saône-et-Loire',
        '89' => '89 — Yonne',
    ],

    // Presets de codes NAF par secteur (cahier des charges, « Questions à trancher »).
    'sectors' => [
        'batiment' => [
            'label' => 'Artisans du bâtiment',
            'naf' => ['41.20A', '43.12A', '43.21A', '43.22A', '43.22B', '43.29A', '43.31Z', '43.32A', '43.32B', '43.33Z', '43.34Z', '43.91A', '43.91B', '43.99C'],
        ],
        'restauration_commerce' => [
            'label' => 'Restauration et commerces',
            'naf' => ['56.10A', '56.10C', '56.30Z', '10.71C', '10.71D', '10.13B', '47.22Z', '47.24Z', '47.76Z', '55.10Z', '55.20Z'],
        ],
        'beaute' => [
            'label' => 'Beauté et bien-être',
            'naf' => ['96.02A', '96.02B', '96.04Z'],
        ],
        'viticulture' => [
            'label' => 'Viticulture et vins',
            // Vigne, vinification, crémant, eaux-de-vie (marc), négociants, cavistes.
            'naf' => ['01.21Z', '11.02A', '11.02B', '11.01Z', '46.34Z', '47.25Z'],
        ],
        'fermes' => [
            'label' => 'Fermes et producteurs',
            // Maraîchage, fruits, élevages (dont charolais), polyculture-élevage, apiculture,
            // fromageries, primeurs. Le code NAF ne dit pas si la ferme vend en direct :
            // les signaux OpenStreetMap (horaires, boutique) aident à le repérer.
            'naf' => ['01.13Z', '01.24Z', '01.25Z', '01.41Z', '01.42Z', '01.45Z', '01.46Z', '01.47Z', '01.49Z', '01.50Z', '10.51C', '47.21Z'],
        ],
        'liberales' => [
            'label' => 'Professions libérales',
            'naf' => ['69.10Z', '69.20Z', '71.11Z', '75.00Z', '86.21Z', '86.23Z', '86.90E', '68.31Z'],
        ],
    ],

    // Tranches d'effectif salarié (codes INSEE).
    'headcount_ranges' => [
        'NN' => 'Non employeuse',
        '00' => '0 salarié',
        '01' => '1 ou 2 salariés',
        '02' => '3 à 5 salariés',
        '03' => '6 à 9 salariés',
        '11' => '10 à 19 salariés',
        '12' => '20 à 49 salariés',
        '21' => '50 à 99 salariés',
    ],
];
