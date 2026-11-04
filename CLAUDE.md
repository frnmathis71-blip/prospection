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
- Horizon exige les extensions `pcntl`/`posix`, absentes sous Windows : en local Windows, installer avec
  `composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` et traiter la file
  avec `php artisan queue:work`. Horizon tourne sur le VPS Linux.
