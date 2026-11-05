# CLAUDE.md — Codenyr Prospection

Outil Laravel personnel de prospection pour Codenyr. Le cahier des charges complet est dans
[`docs/cahier-des-charges.md`](docs/cahier-des-charges.md) (original : `docs/cahier-des-charges.pdf`).

## Règles de travail données à l'IA

- **Une mission par session.** Lire le cahier des charges et ce CLAUDE.md avant de commencer.
- **Tests écrits avec Pest.** Aucun appel réel à une API ou à un serveur mail dans les tests
  (`Http::fake()`, `Mail::fake()`, `Queue::fake()`).
- **Une mission est terminée quand** : `php artisan test` est entièrement vert, Pint ne signale rien
  (`./vendor/bin/pint --test`), et les vérifications manuelles de la mission ont été faites.
- **Un commit (ou une branche + merge) par mission**, avec le numéro de mission dans le message
  (ex. « M2 - Modèle de données »).
- **Ne jamais modifier le code d'une mission validée** sans le signaler et relancer toute la suite de tests.

## Interdits — Développement (consignes pour l'IA)

Une mission qui enfreint une de ces règles n'est pas validée, même si ses tests passent.

| Interdit | Pourquoi |
| --- | --- |
| Mettre un secret dans le code, un commit ou la conversation | Fuite des accès ; tout passe par le `.env` |
| Envoyer de vrais emails ou appeler de vraies API dans les tests | Envois accidentels à des prospects, quotas consommés |
| Envoyer un email sans passer par la file d'attente, les quotas et la liste d'opposition | Une seule exception suffit à casser la conformité |
| Purger la liste d'opposition | Elle doit survivre à toutes les purges et réinitialisations |
| Exposer l'outil, Horizon ou les logs sans authentification | Données personnelles accessibles à tous |
| Lancer un envoi réel sans l'accord explicite de l'utilisateur | L'IA prépare ; seul l'utilisateur déclenche une campagne |

Les autres interdits (collecte des données, envoi des emails, contenu des messages) sont dans le
cahier des charges, section « Interdits — à ne jamais faire », et s'appliquent aussi.

## Repères techniques

- Laravel 13, PHP 8.4, Filament 5 (panneau `admin` sur `/admin`), Horizon sur `/horizon`.
- Un seul utilisateur, sans inscription publique ; créé avec `php artisan make:filament-user`.
- Double authentification TOTP (Filament, secret chiffré par `APP_KEY`), obligatoire par défaut.
  `AUTH_TWO_FACTOR_REQUIRED=false` la coupe en local uniquement ; elle reste toujours imposée en
  production (`App\Support\TwoFactor`). Ne jamais affaiblir cette règle pour la production.
- Horizon : middleware `auth` (redirection vers la connexion) + gate `viewHorizon` (2FA activée requise
  quand elle est obligatoire), y compris en local.
- Tests : SQLite en mémoire, `Http::preventStrayRequests()` actif dans tous les tests Feature.
  `tests/Database` contient les tests qui gèrent eux-mêmes la base (`migrate:fresh`), sans transaction.
- Modèle de données (M2) : noms de colonnes en anglais, correspondance avec le cahier des charges en
  commentaire dans chaque migration. Statuts et types = enums PHP dans `app/Enums` (libellés français).
- Emails et domaines toujours stockés en minuscules ; `SuppressionEntry::blocks($email)` vérifie
  l'adresse ET son domaine. La table `suppression_list` n'est jamais purgée ni modifiable depuis l'interface.
- Import CSV (M3) : `App\Filament\Imports\CompanyImporter`, lancé depuis la liste Entreprises, traité en
  file d'attente (un worker doit tourner). Dédoublonnage : SIREN, puis domaine propre (site ou email pro,
  jamais un réseau social ni une messagerie grand public, cf. `App\Support\Domain`) ou téléphone normalisé
  (`App\Support\Phone`). Un import complète les champs vides, il n'écrase jamais une donnée existante.
- Messages de validation en français : `lang/fr/validation.php`.
- Recherche d'entreprises (M4) : `App\Jobs\RunCompanySearch` (API Recherche d'entreprises, paginée, puis
  BODACC pour exclure les procédures collectives, que l'API ne fournit pas), puis `App\Jobs\EnrichCompany`
  (OpenStreetMap/Overpass puis devinette de domaine, débit limité par le RateLimiter « enrichment »).
  Toute API externe passe par `App\Services\ExternalHttp` (User-Agent, nouvelles tentatives sur 429/5xx) ;
  une indisponibilité lève `ApiUnavailableException` et le job est remis en file (jamais d'échec brutal).
  Tout site visité passe d'abord par `App\Services\RobotsTxt`. DNS isolé dans `App\Services\DnsResolver`
  (à simuler dans les tests). Réglages et presets NAF : `config/prospection.php`.
- Analyse des sites (M5) : `App\Jobs\AnalyzeWebsite` (file `enrichment`, RateLimiter « analysis »), lancé après
  `EnrichCompany`, après un import CSV, par `php artisan app:analyze-websites` ou depuis Filament.
  `App\Services\WebsiteAnalyzer` : une visite de l'accueil (HTTPS d'abord, redirections suivies, robots.txt),
  classement de l'état (`WebsiteState`), puis `HtmlInspector` et `PageSpeedClient` (facultatif, jamais bloquant).
  Problèmes = codes `App\Enums\WebsiteProblem` dans `websites.problems` ; libellés en clair via
  `Website::problemLabels()` (réutilisés dans les emails : ne citer que des constats réellement détectés).
  Pages d'exemple des tests : `tests/Fixtures/websites/`.
- Données OpenStreetMap : mentionner « © contributeurs OpenStreetMap » là où elles sont affichées.
- Données de démo (`DemoSeeder`) : entreprises fictives, adresses en `.example` uniquement (domaine
  réservé, aucun email ne peut y arriver), jamais chargées en production.
- Horizon exige les extensions `pcntl`/`posix`, absentes sous Windows : en local Windows, installer avec
  `composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` et traiter la file
  avec `php artisan queue:work --queue=default,enrichment`. Horizon tourne sur le VPS Linux.
- Deux files : `default` (imports, recherches) passe avant `enrichment` (EnrichCompany, débit limité).
  `retry_after` des files (960 s) doit rester supérieur au plus long job (RunCompanySearch : 900 s).
- Overpass saturé : pause commune à tous les jobs (`App\Services\OverpassPause`, 1 min → 1 h), jamais de
  nouvelle tentative immédiate. Signal de vie du worker : `App\Support\WorkerHeartbeat` (tableau de bord).
