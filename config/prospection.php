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

    // Hébergement : « vps » (Redis, Horizon supervisé, cron chaque minute) ou « shared » (mutualisé OVH :
    // file et cache en base, une tâche planifiée par heure qui lance app:shared-hosting-cron).
    'hosting' => env('HOSTING_MODE', 'vps'),

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

    // Analyse des sites (M5) : une visite de la page d'accueil, robots.txt respecté.
    'website_analysis' => [
        'timeout' => 15,
        'per_minute' => (int) env('PROSPECTION_ANALYSES_PER_MINUTE', 20),
    ],

    // Google PageSpeed Insights (gratuit). Une clé gratuite augmente le quota ; sans clé, le
    // quota partagé est vite atteint et le score reste alors vide (l'analyse continue).
    'pagespeed' => [
        'enabled' => (bool) env('PAGESPEED_ENABLED', true),
        'url' => 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed',
        'key' => env('PAGESPEED_API_KEY'),
        'timeout' => 90,
        'slow_below' => 50, // score mobile en dessous duquel « le site est lent sur mobile »
    ],

    // Recherche d'emails (M7) : accueil, page contact et mentions légales, robots.txt respecté,
    // avec un délai entre deux pages d'un même site. Aucune adresse n'est devinée.
    'email_search' => [
        'timeout' => 15,
        'delay_seconds' => (int) env('PROSPECTION_EMAIL_SEARCH_DELAY', 2),
        'per_minute' => (int) env('PROSPECTION_EMAIL_SEARCHES_PER_MINUTE', 10),
        'max_emails_per_site' => 5, // minimisation : on ne garde pas des listes entières
    ],

    /*
    | Emails de prospection (M8) : identité de l'expéditeur et mentions obligatoires, ajoutées
    | automatiquement en pied de chaque email. Tant qu'un champ obligatoire est vide, aucun
    | email ne peut être envoyé (l'aperçu le signale).
    */
    'sender' => [
        'name' => env('SENDER_NAME'),               // ton prénom et ton nom (email envoyé à ton nom)
        'company' => env('SENDER_COMPANY', 'Codenyr'),
        'address' => env('SENDER_ADDRESS'),         // adresse postale de l'entreprise
        'siret' => env('SENDER_SIRET'),
        'phone' => env('SENDER_PHONE'),             // facultatif
        'website' => env('SENDER_WEBSITE', 'https://codenyr.fr'),
        'privacy_url' => env('SENDER_PRIVACY_URL', 'https://codenyr.fr/confidentialite'),
    ],

    'emails' => [
        // Bouton unique de l'email : lien vers codenyr.fr avec paramètres UTM (pas de suivi individuel).
        'cta_url' => env('EMAIL_CTA_URL', 'https://codenyr.fr'),
        'cta_label' => 'Voir mes réalisations',
        // Bandeau d'en-tête aux couleurs de codenyr.fr, intégré à l'email (EMAIL_HEADER_IMAGE=false pour le retirer).
        'header_image' => env('EMAIL_HEADER_IMAGE', true) ? resource_path('images/email-header.jpg') : null,
        'utm_source' => 'prospection',
        'utm_medium' => 'email',
        // Longueur conseillée du corps (cahier des charges : 80 à 150 mots).
        'min_words' => 80,
        'max_words' => 150,
        // Durée de conservation annoncée dans la mention d'information.
        'retention_years' => 3,
        // Adresses (les tiennes) autorisées pour « Envoyer un test », séparées par des virgules.
        'test_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('EMAIL_TEST_RECIPIENTS', ''))))),
    ],

    /*
    | Envoi (M9) : jours ouvrés (hors week-end et jours fériés), créneaux horaires, espacement
    | aléatoire entre deux emails et plafond quotidien global (préchauffage du domaine, qui
    | s'ajoute aux quotas de chaque campagne).
    */
    'sending' => [
        'timezone' => 'Europe/Paris',
        'slots' => [['08:00', '11:00'], ['14:00', '17:00']],
        'spacing_minutes' => [2, 6],
        'daily_cap' => (int) env('SENDING_DAILY_CAP', 10),
    ],

    /*
    | Lecture de la boîte d'envoi (M10) : réponses, réponses automatiques et rebonds.
    | Lecture seule : les messages ne sont ni supprimés ni marqués comme lus.
    */
    'imap' => [
        'host' => env('IMAP_HOST'),
        'port' => (int) env('IMAP_PORT', 993),
        'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
        'validate_cert' => (bool) env('IMAP_VALIDATE_CERT', true),
        'username' => env('IMAP_USERNAME'),
        'password' => env('IMAP_PASSWORD'),
        'folder' => env('IMAP_FOLDER', 'INBOX'),
        'lookback_days' => 3, // messages relus à chaque passage (les doublons sont ignorés)
    ],

    // Enrichissements par minute (OpenStreetMap + devinette), pour un usage raisonnable.
    'enrichments_per_minute' => (int) env('PROSPECTION_ENRICHMENTS_PER_MINUTE', 20),

    /*
    | Score de priorité (M6) : poids PAR DÉFAUT, modifiables dans « Réglages du score ».
    | Les points sont additionnés puis ramenés sur 100 (100 = meilleur prospect possible
    | avec les poids actuels).
    */
    'scoring' => [
        // Opportunité liée au site (critère principal).
        'website_none' => 60,          // aucun site
        'website_social_only' => 55,  // réseau social seul
        'website_directory' => 50,     // annuaire ou mini-site gratuit
        'website_offline' => 50,       // site hors ligne
        'website_parking' => 50,       // page parking
        'website_unknown' => 20,       // pas encore analysé
        'website_outdated' => 25,      // site obsolète : base, + points par problème
        'problem_high' => 12,          // problème fort (HTTPS, mobile)
        'problem_medium' => 6,         // problème moyen (copyright, technologies, lenteur)
        'problem_low' => 2,            // problème faible (mentions légales, contact)
        'website_max' => 60,           // plafond de la partie « site »
        // Joignabilité.
        'phone' => 8,                 // téléphone connu
        'email_pro' => 8,             // email générique ou nominatif (non personnel)
        'email_personal' => 4,         // email personnel seulement (revue manuelle)
        'osm_active' => 4,             // horaires ou réseaux sociaux renseignés sur OpenStreetMap
        // Profil de l'entreprise.
        'new_company' => 12,           // créée il y a moins de 6 mois : cible « création de site »
        'established' => 8,           // créée il y a 2 ans ou plus : entreprise installée
        'headcount_small' => 6,       // 1 à 9 salariés
        'headcount_micro' => 3,        // sans salarié
        'headcount_medium' => 3,       // 10 à 49 salariés
        // Bonus par secteur (clés de « sectors »).
        'sector_batiment' => 4,
        'sector_restauration_commerce' => 4,
        'sector_beaute' => 4,
        'sector_viticulture' => 4,
        'sector_fermes' => 4,
        'sector_liberales' => 4,
    ],

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
            'wording' => 'artisans du bâtiment', // variable {secteur} des emails
            'naf' => ['41.20A', '43.12A', '43.21A', '43.22A', '43.22B', '43.29A', '43.31Z', '43.32A', '43.32B', '43.33Z', '43.34Z', '43.91A', '43.91B', '43.99C'],
        ],
        'restauration_commerce' => [
            'label' => 'Restauration et commerces',
            'wording' => 'restaurants et commerces',
            'naf' => ['56.10A', '56.10C', '56.30Z', '10.71C', '10.71D', '10.13B', '47.22Z', '47.24Z', '47.76Z', '55.10Z', '55.20Z'],
        ],
        'beaute' => [
            'label' => 'Beauté et bien-être',
            'wording' => 'professionnels de la beauté',
            'naf' => ['96.02A', '96.02B', '96.04Z'],
        ],
        'viticulture' => [
            'label' => 'Viticulture et vins',
            'wording' => 'domaines viticoles',
            // Vigne, vinification, crémant, eaux-de-vie (marc), négociants, cavistes.
            'naf' => ['01.21Z', '11.02A', '11.02B', '11.01Z', '46.34Z', '47.25Z'],
        ],
        'fermes' => [
            'label' => 'Fermes et producteurs',
            'wording' => 'producteurs locaux',
            // Maraîchage, fruits, élevages (dont charolais), polyculture-élevage, apiculture,
            // fromageries, primeurs. Le code NAF ne dit pas si la ferme vend en direct :
            // les signaux OpenStreetMap (horaires, boutique) aident à le repérer.
            'naf' => ['01.13Z', '01.24Z', '01.25Z', '01.41Z', '01.42Z', '01.45Z', '01.46Z', '01.47Z', '01.49Z', '01.50Z', '10.51C', '47.21Z'],
        ],
        'liberales' => [
            'label' => 'Professions libérales',
            'wording' => 'professions libérales',
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
