# Codenyr Prospection

Outil Laravel personnel de prospection pour Codenyr. Cahier des charges : [`docs/cahier-des-charges.md`](docs/cahier-des-charges.md).
Règles de travail pour l'IA : [`CLAUDE.md`](CLAUDE.md).

## Lancer le projet en local (Windows + Herd)

Prérequis : PHP 8.4, Composer, une base MySQL (ou PostgreSQL) et un serveur Redis.

```bash
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
cp .env.example .env
php artisan key:generate
# remplir .env (base de données, Redis), puis :
php artisan migrate
php artisan make:filament-user
```

- Interface : http://codenyr-prospection.test/admin (Herd) — première connexion : configuration de la 2FA.
- File d'attente sous Windows, **depuis le dossier du projet** : `php artisan queue:work --queue=default,enrichment`
  (imports et recherches d'abord, enrichissements ensuite ; Horizon nécessite Linux : `php artisan horizon` sur le VPS).
  Après une modification du code, relancer le worker (ou `php artisan queue:restart`).
- Tableau de bord des files : http://codenyr-prospection.test/horizon (connecté avec 2FA).
- Contrôle de la file : `php artisan app:queue-check`.
- Données de démonstration fictives (hors production) : `php artisan db:seed`.
- Import CSV : bouton « Importer un CSV » dans Entreprises. Le fichier est traité par la file d'attente :
  le worker doit tourner. Une notification (cloche) annonce la fin et donne le rapport des
  lignes en erreur. Un modèle de fichier est téléchargeable dans la fenêtre d'import.
- Recherches d'entreprises : menu « Recherches » → créer (zone, secteur, effectif, ancienneté) → « Lancer ».
  Les entreprises sont ajoutées puis complétées (OpenStreetMap, devinette de domaine) par la file
  d'attente. Relance automatique possible chaque lundi ou chaque mois : le planificateur doit tourner
  (`php artisan schedule:work` en local, cron `schedule:run` en production).
- Analyse des sites : automatique après une recherche ou un import ; sinon bouton « Analyser les sites »
  (liste Entreprises), « Analyser le site » (fiche) ou `php artisan app:analyze-websites`. Pour le score
  PageSpeed, renseigner une clé gratuite `PAGESPEED_API_KEY` dans le `.env`.
- Score de priorité (0-100) : onglet « À traiter » de la liste Entreprises, du meilleur au moins bon ; détail
  « Pourquoi ce score ? » sur chaque fiche ; poids modifiables dans « Réglages du score ».
  Recalcul manuel : `php artisan app:recalculate-scores`.
- Emails de contact : cherchés automatiquement après l'analyse d'un site en ligne (accueil, page contact,
  mentions légales) ; sinon « Chercher les emails » (fiche ou action groupée) ou `php artisan app:find-emails`.
  Chaque adresse est vérifiée (syntaxe + MX) ; `php artisan app:verify-emails` vérifie celles encore « À vérifier ».
  Les adresses personnelles (gmail.com, orange.fr…) sont dans Contacts → « À revoir », à valider à la main.
- Liste d'appels : prospects à traiter avec un téléphone mais sans email utilisable, avec le bouton
  « Résultat de l'appel » (noté dans l'historique).
- Templates d'email : menu « Templates ». Templates de départ à relire : `php artisan db:seed --class=TemplateSeeder`.
  Renseigner l'identité de l'expéditeur dans le `.env` (`SENDER_NAME`, `SENDER_ADDRESS`, `SENDER_SIRET`) et ses
  propres adresses de test (`EMAIL_TEST_RECIPIENTS`). « Aperçu » montre l'email rendu avec un vrai prospect ;
  « M'envoyer un test » l'envoie à ces adresses uniquement (worker requis).
- Campagnes : menu « Campagnes » → créer (séquence, liste ciblée, quotas) → « Lancer la campagne » (confirmation
  explicite). Les emails partent ensuite un par un, jours ouvrés, 8 h-11 h et 14 h-17 h, 2 à 6 minutes d'écart :
  le worker ET le planificateur doivent tourner (`php artisan schedule:work` en local). Plafond global du domaine :
  `SENDING_DAILY_CAP`. Séquences J0 / J+4 / J+12 de départ : créées par `TemplateSeeder`.
- Relances, réponses, rebonds : les relances J+4 / J+12 (jours ouvrés) partent seules. La boîte d'envoi est lue
  toutes les 5 minutes (IMAP, lecture seule) dès que `IMAP_HOST`, `IMAP_USERNAME` et `IMAP_PASSWORD` sont
  renseignés : une réponse arrête la séquence et s'affiche dans la cloche ; les absences sont ignorées ; un rebond
  dur ajoute l'adresse à la liste d'opposition. Lecture manuelle : `php artisan app:read-inbox`.
- Tableau de bord : état de la file (tâches en attente, worker arrêté, échecs) et suivi des imports
  (en attente, en cours avec progression, terminé, rapport d'erreurs), mis à jour toutes les 5 secondes.

## Qualité

```bash
php artisan test
./vendor/bin/pint --test
```

La CI GitHub Actions (`.github/workflows/ci.yml`) lance Pint et les tests à chaque push.
