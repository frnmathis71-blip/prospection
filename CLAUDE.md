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
- Score (M6) : `App\Services\CompanyScorer` (points ramenés sur 100, détail dans `companies.score_details`).
  Poids par défaut dans `config/prospection.php` (« scoring »), surchargés par la page « Réglages du score »
  (table `settings`, clé `scoring_weights`) ; changement de poids → `RecalculateScores`. Recalcul après chaque
  analyse, contact enregistré, création (recherche, import, formulaire) et modification de fiche. Le score
  n'est jamais saisi à la main. Liste « À traiter » : scope `Company::toProcess()` (jamais de statut bloquant).
- Emails (M7) : `App\Jobs\FindContactEmails` (file `enrichment`, RateLimiter « email_search »), lancé après une
  analyse qui trouve un site en ligne (`EmailFinder::CRAWLABLE_STATES`), depuis Filament ou par
  `php artisan app:find-emails`. `App\Services\EmailFinder` : accueil + page contact + mentions légales (liens de
  l'accueil, même site), robots.txt par page, `Sleep` entre deux pages ; dans les mentions légales, seules les
  adresses du domaine du site ou d'une messagerie personnelle sont gardées (pas l'agence web ni l'hébergeur).
  `App\Services\EmailExtractor` : mailto:, texte, formes masquées, protection Cloudflare. Aucune adresse devinée.
  Toute nouvelle adresse (quelle que soit sa source) est vérifiée par `App\Jobs\VerifyContact` (événement `created`
  du modèle Contact) : syntaxe, adresse jetable, MX via `DnsResolver`. Adresse personnelle (gmail.com…) =
  `Contact::needsReview()` tant qu'elle n'est pas validée à la main (onglet « À revoir ») : à exclure des envois (M9).
  Liste d'appels : page `CallList`, scope `Company::toCall()` (à traiter + téléphone + aucun email non invalide).
  Tests : le DNS est simulé partout (`Tests\Fakes\FakeDnsResolver`, branché dans `tests/Pest.php`).
- Templates (M8) : `App\Services\TemplateRenderer` rend un `Template` pour une entreprise (+ contact) en
  `RenderedEmail` (objet, HTML, texte brut, `blocking`, `warnings`). Variables `TemplateRenderer::VARIABLES`,
  valeur de secours `{variable|secours}` ; variable vide sans secours, variable inconnue ou identité de l'expéditeur
  incomplète (`config('prospection.sender')`, .env `SENDER_*`) = envoi bloqué (`canBeSent()`). Bouton UTM, mention
  d'information, confidentialité et désinscription ajoutés automatiquement ; aucune image (pixel) possible.
  Mailable `App\Mail\ProspectMail` (en-têtes List-Unsubscribe). Désinscription : route publique signée
  `unsubscribe` (`/desinscription?e=…`, GET et POST One-Click, sans CSRF) → liste d'opposition. L'aperçu Filament
  utilise un lien NON signé (`preview: true`) pour ne jamais désinscrire un prospect par erreur. « M'envoyer un
  test » : uniquement vers `EMAIL_TEST_RECIPIENTS`, par la file. Templates de départ : `TemplateSeeder`.
- Envoi (M9) : une campagne (liste ciblée `campaigns.criteria` + séquence + quotas) naît en brouillon ; seul
  l'utilisateur la lance (action « Lancer la campagne » + case de confirmation). Le lancement met en file les
  emails de l'étape 1 (`CampaignAudience::enqueue`, une adresse envoyable par entreprise, unique
  campaign/company/step en base) ; RIEN ne part au lancement. Le job planifié `SendNextEmail` (chaque minute,
  `tries = 1`) appelle `EmailSender::sendNext()` : au plus un email, sous verrou, si jour ouvré + créneau
  (`App\Support\BusinessCalendar`, jours fériés compris), espacement aléatoire écoulé (cache `sending:next-at`),
  plafond global `SENDING_DAILY_CAP` et quotas de la campagne non atteints. `reasonNotToSend()` revérifie juste
  avant l'envoi (liste d'opposition, statut, vérification, revue) : sinon l'email est annulé avec sa raison.
  Tests : heure de Paris simulée (`travelTo`), `Mail::fake()`.
- Relances et boîte (M10) : `App\Services\FollowUpScheduler` met en file l'étape N+1 le jour ouvré prévu (délai
  compté depuis l'envoi du J0, `BusinessCalendar::addBusinessDays`), appelé au début de chaque `sendNext()` ; une
  relance reprend la conversation (References / In-Reply-To). Séquence arrêtée si un email de la campagne est
  `Replied`/`Bounced`, si le prospect n'est plus « Contacté / Relancé », ou sur opposition. Boîte d'envoi : interface
  `App\Services\Inbox\Mailbox` (IMAP réel `ImapMailbox`, lecture seule ; simulée dans les tests), job planifié
  `ReadInbox`, `InboxProcessor` (réponse, absence ignorée, rebond dur → invalide + opposition, rebond temporaire →
  une nouvelle tentative), chaque message traité une fois (`inbound_messages`). Messages de test : `tests/Fixtures/inbox/*.eml`.
- Tableau de bord (M11) : `App\Services\ProspectingStats` (entonnoir emboîté trouvés → avec email → contactés →
  réponses → devis → signés ; taux par template A/B, secteur, zone ; santé de l'envoi) et widgets `FunnelWidget`,
  `SendingHealthWidget`, `RatesWidget`. Coupe-circuit `App\Services\CircuitBreaker` : > 3 % de rebonds ou > 2 % de
  désinscriptions sur 7 jours (depuis le dernier réarmement) → toutes les campagnes actives en pause + notification ;
  vérifié avant chaque envoi, après chaque rebond ou désinscription, et chaque heure. Tant qu'il est déclenché, rien
  ne part et aucune campagne ne se lance ni ne reprend ; réarmement manuel (liste des campagnes).
- RGPD et production (M12) : `App\Services\ProspectPurger` (prospects sans réponse dont le dernier contact, la
  collecte ou la dernière note date de plus de 3 ans ; statuts Replied / QuoteSent / Won conservés ; la liste
  d'opposition n'est JAMAIS touchée), planifié chaque nuit (`app:purge-prospects`). `App\Services\PersonalDataExporter`
  (droit d'accès : tout ce qui concerne une adresse, avec sa source), page Filament « Registre RGPD » et
  `app:export-person`. Sauvegardes spatie/laravel-backup (base seule, chiffrée par `BACKUP_ARCHIVE_PASSWORD`, disques
  `BACKUP_DISKS`). `app:production-check` liste ce qui manque avant la production. Déploiement : `docs/deploiement.md`
  et `deploy/` (nginx, Supervisor/Horizon, crontab, deploy.sh).
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
