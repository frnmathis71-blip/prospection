# Cahier des charges — Outil de prospection Codenyr

> Texte extrait automatiquement de `docs/cahier-des-charges.pdf` (4 novembre 2026), qui fait foi.
> La mise en forme des tableaux est perdue à l'extraction : en cas de doute, se référer au PDF.

```text
Cahier des charges — Outil de prospection
Codenyr
4 novembre 2026
Contexte et objectifs
Outil Laravel personnel qui trouve des entreprises sans site web ou avec un site obsolète, puis leur
envoie une présentation de Codenyr (site vitrine, devis gratuit, bouton vers codenyr.fr).
Utilisateur : toi seul, pas de multi-comptes ni de gestion de rôles.
Objectif mesurable : obtenir des demandes de devis, pas des ouvertures d’email. Le KPI
principal = réponses + clics + devis signés.
Hors périmètre V1 : facturation, gestion de projet client, envoi de SMS ou appels automatisés.
Points bloquants à trancher avant de coder
1. Une entreprise sans site n’a souvent pas d’email trouvable. C’est le plus gros angle mort du
projet. Les annuaires officiels (Sir ene) ne donnent pas d’email, et Google Maps donne téléphone et
adresse mais rarement un email. Conséquences à prévoir :
Les prospects « sans site » auront un taux d’email trouvé faible (à mesurer dès le MVP).
L’outil doit gérer d’autres canaux pour ces leads : courrier postal (légal en opt-out, très
efficace pour les artisans), liste d’appels à faire toi-même, page Facebook.
Les prospects « site obsolète » sont l’inverse : l’email est presque toujours sur le site (page
contact, mentions légales).
2. Le scraping de Google Maps ou P agesJaunes est interdit par leurs CGU. Passer par des API
officielles (voir Module 1), toutes gratuites.
3. Le suivi d’ouverture par pixel est désormais soumis au consentement pour un prospect
froid (voir Cadre légal). L’outil ne doit pas en mettre.
4. Envoyer depuis ton domaine principal codenyr .fr est risqué : un signalement spam peut
dégrader la réception de tes emails clients. Prévoir un domaine ou sous-domaine dédié (Module 5).
Cadre légal (RGPD / CNIL)
La prospection B2B par email est autorisée sans consentement préalable (régime opt-out), à condition
que le message soit en lien avec l’activité du destinataire, qu’il soit informé et qu’il puisse s’opposer
simplement. Ce n’est pas un avis juridique ; à valider si tu as un doute.
Ce que l’outil doit imposer techniquement :

Identité claire de l’expéditeur dans chaque email (Codenyr, nom, adresse, SIRET).
Lien de désinscription en un clic + en-tête List-Unsubscribe / List-Unsubscribe-Post .
Liste d’opposition (repoussoir) permanente : une adresse ou un domaine désinscrit n’est plus
jamais contacté, même s’il est réimporté.
Mention d’information : d’où vient la donnée (ex. annuaire public), finalité, dur ée de
conservation, lien vers une page confidentialité sur codenyr	.fr.
Exclure les adresses visiblement personnelles (gmail.com, orange.fr…) d’un entrepreneur
individuel si le message n’est pas clairement pro, ou les marquer pour revue manuelle.
Durée de conservation : purge automatique des prospects sans réponse après 3 ans (pratique
courante recommandée par la CNIL).
Aucun pixel de suivi d’ouverture. Depuis la recommandation CNIL du 14 avril 2026, un pixel
individuel exige le consentement, même quand l’email lui-même r elève de l’opt-out B2B.
Registre de traitement simple (une page dans l’outil ou un doc) décrivant ce fichier de
prospection.
Sources : CNIL — « La prospection commerciale par courrier électronique » (cnil.fr) ; BCLP — « Pixels
de suivi dans les emails » (bclplaw.com) ; Legiscope — « RGPD et marketing direct ».
Interdits — à ne jamais faire
Ces règles s’imposent à toi comme à l’IA. Une mission qui en enfreint une n’est pas validée, même si
ses tests passent.
Collecte des données
Interdit	Pourquoi
Scraper Google Maps, PagesJaunes, LinkedIn,
Societe.com
Interdit par leurs CGU ; une base obtenue par
scraping est un motif fréquent de sanction CNIL
Acheter, louer ou échanger des fichiers d’emails Origine et consentement invérifiables, adresses
souvent mortes ou pièges à spam
Générer en masse des adresses nominatives
devinées (prenom.nom@)
Taux de rebond élevé et encadrement CNIL strict : on
s’en tient aux adresses publiées par l’entreprise
Contourner robots.txt, captchas ou limites de débit
(proxys, rotation d’IP, faux navigateurs)
Accès non autorisé, blocage, voire risque pénal
Collecter plus que nécessaire (téléphone ou adresse
perso du dirigeant, réseaux sociaux privés)
Principe de minimisation du RGPD
Marteler les API publiques	Respecter leurs limites de débit, sinon blocage de ta
clé ou de ton IP

Envoi des emails
Interdit	Pourquoi
Envoyer depuis codenyr.fr ou une boîte Gmail
personnelle
Un signalement spam pénaliserait tes emails clients ;
Gmail bloque l’envoi en masse
Envoyer en volume sans préchauffage	Domaine classé spam dès les premiers jours, difficile
à rattraper
Pixel de suivi d’ouverture ou tracking individuel cachéConsentement exigé par la CNIL depuis avril 2026
Prospecter des particuliers	En B2C, le consentement préalable est obligatoire
Prospecter par SMS ou appels automatisés	Régime différent, consentement requis
Recontacter une adresse désinscrite, même après
réimport
Infraction directe, sanctionnée
Cacher, compliquer ou retarder la désinscription Obligation légale d’opposition simple et gratuite
Plusieurs domaines ou boîtes en rotation pour passer
les filtres
Technique de spammeur, détectée et blacklistée
Plus de 3 emails par prospect dans une séquence Plaintes, désinscriptions, image dégradée
Faux « Re: » ou « Fwd: », faux expéditeur, objet
trompeur
Interdit par la loi (LCEN) et destructeur de confiance
Pièces jointes, raccourcisseurs de liens (bit.ly), email
tout en image
Déclenchent les filtres anti-spam
Contenu des messages
Interdit	Pourquoi
Exagérer les défauts d’un site (« votre site va être
supprimé », « Google va vous pénaliser »)
Pratique commerciale trompeuse ; seuls les
problèmes réellement détectés sont cités
Fausse urgence ou fausse offre limitée	Pratique commerciale trompeuse
Montrer un site client ou son logo sans son accord Droit à l’image et confiance client
Dénigrer le prestataire actuel du prospect	Dénigrement, risque juridique
Rendre publique ou indexable une page « mini-audit »
nominative
Les défauts d’une entreprise nommée ne doivent pas
être visibles sur Google : liens secrets et noindex
Développement (consignes pour l’IA)
Interdit	Pourquoi
Mettre un secret dans le code, un commit ou la
conversation
Fuite de tes accès ; tout passe par le .env
Envoyer de vrais emails ou appeler de vraies API dans
les tests
Envois accidentels à des prospects, quotas
consommés

Interdit	Pourquoi
Envoyer un email sans passer par la file d’attente, les
quotas et la liste d’opposition
Une seule exception suffit à casser la conformité
Purger la liste d’opposition	Elle doit survivre à toutes les purges et
réinitialisations
Exposer l’outil, Horizon ou les logs sans
authentification
Données personnelles accessibles à tous
Lancer un envoi réel sans ton accord explicite L’IA prépare ; toi seul déclenches une campagne
Module 1 — Recherche d’entreprises
Combiner une source officielle (qui e xiste, statut, secteur) et une source « terrain » (site web,
téléphone).
Source	Ce qu’elle apporte Coût	Rôle
API Recherche
d’entreprises (recherche-
entreprises.api.gouv.fr)
SIREN, code NAF,
adresse, dirigeants, état
actif/fermé
Gratuite, sans clé Base principale, filtrage
par secteur et commune
API Sirene (INSEE) Données Sirene
complètes, date de
création
Gratuite (compte INSEE)Complément
OpenStreetMap (API
Overpass)
Commerces géolocalisés
avec, quand c’est
renseigné, site web,
téléphone, email,
horaires
Gratuite (citer « ©
OpenStreetMap », usage
raisonnable)
Savoir si un site est
déclaré
Devinette de domaine Test de nomentreprise.fr /
.com par DNS puis HTTP
Gratuite	Repérer un site non
déclaré
Import CSV manuel Tes propres listes Gratuit	Indispensable pour tester
Objectif 0 € : Google Places, Pappers et les vérificateurs d’email payants sont e	xclus.
Filtres de recherche à prévoir :
Zone : commune, département, rayon en km autour d’un point.
Secteur : codes NAF (artisans du BTP, restauration, coiffur	e, commerces, professions
libérales…), avec des presets enregistrables.
Taille : tranche d’effectif, ancienneté (une entr	eprise créée il y a moins de 6 mois est une cible «
création de site »).
État : actives uniquement ; exclure celles en procédure collective.
Signaux OpenStreetMap : horaires, téléphone ou réseaux sociaux renseignés = entreprise active
et joignable.

Fonctionnement : recherches sauvegardées et relançables (ex. « Plombiers 71 — chaque lundi »),
déduplication par SIREN puis par domaine/téléphone, traitement en tâches de fond (queues).
Module 2 — Détection « pas de site » ou « site obsolète » +
scoring
« Pas de site » n’est pas binaire. Distinguer :
Aucun site déclaré nulle part.
Uniquement une page Facebook / Instagram / Google Business.
Domaine qui ne répond plus, page parking, certificat e	xpiré.
Site sur une plateforme d’annuaire (PagesJaunes, mini-site gratuit).
Critères d’obsolescence à analyser automatiquement :
Critère	Comment le détecter	Poids suggéré
Pas de HTTPS	Requête HTTP, redirection, validité
du certificat
Fort
Pas responsive	Absence de balise meta viewport Fort
Performance mobile faible API Google PageSpeed Insights
(gratuite)
Moyen
Copyright ancien	Année dans le footer (ex. © 2014)Moyen
Technologies datées	jQuery < 3, Flash, frames,
tableaux de mise en page, vieux
WordPress/Joomla (meta
generator)
Moyen
Pas de mentions légales Recherche de la page	Faible (mais bon argument)
Pas de formulaire de contact Analyse du HTML	Faible
Score de priorité (0-100) calculé sur ces critères + taille, ancienneté, secteur. Tu travailles toujours
les meilleurs scores en premier.
Idée forte : stocker les problèmes détectés pour les réutiliser dans l’email (« votre site n’est pas
sécurisé en HTTPS et s’affiche mal sur mobile »). Un email qui parle de leur site convertit bien mieux
qu’une présentation générique.
Prévoir aussi une capture d’écran du site (Browsershot / Puppeteer) pour juger en un coup d’œil
dans l’interface.
Module 3 — Recherche de l’email de contact
1. Crawl léger du site existant : page d’accueil, contact, mentions légales (mailto: et regex email),
en respectant robots.txt et un délai entre requêtes.
2. Champ email d’OpenStreetMap quand il est renseigné.

3. Saisie manuelle (tu trouves l’email sur Facebook par exemple).
Vérification avant envoi (réduit les rebonds, qui ruinent la délivrabilité) :
Syntaxe + enregistrement MX du domaine.
Détection des adresses jetables et des domaines « catch-all ».
Pas de vérificateur payant : syntax e + MX + gestion des rebonds suffisent.
Préférer les adresses génériques (contact@, info@) : c’est le cas le plus simple côté légal. Une
adresse nominative reste possible si le message concerne bien sa fonction.
Chaque email garde sa source et sa date de collecte (exigé pour répondre à une demande « d’où
tenez-vous mon adresse ? »).
Module 4 — Emails, templates et relances
Templates multiples, au minimum deux angles :
« Sans site » : présentation du site vitrine, « vos clients vous cherchent sur Google », devis
gratuit, bouton vers codenyr.fr.
« Site obsolète » : 2-3 problèmes concrets détectés sur leur site, proposition de refonte, devis
gratuit.
Variantes par secteur (artisan, restaurant, santé…) avec un exemple de réalisation pertinent.
Variables de personnalisation : {nom_entreprise}, {ville}, {secteur}, {prenom_dirigeant},
{problemes_detectes} , {annee_creation}.
Bonnes pratiques de rédaction intégrées à l’outil :
Email court (80 à 150 mots), ton humain, envoyé à ton nom, pas « l’équipe Codenyr ».
Peu d’images, pas de pièce jointe (les PDF déclenchent les filtr	es anti-spam) ; une version texte
brut systématique.
Un seul appel à l’action : le bouton vers codenyr.fr, avec paramètres UTM
(utm_source=prospection&utm_campaign=... ).
Prévisualisation avec les données d’un vrai prospect avant tout envoi.
A/B test d’objets (2 variantes, comparaison du taux de réponse).
Séquence de relance automatique :
1. J0 : email de présentation.
2. J+4 à J+5 : relance courte (« je me permets de revenir vers vous… »).
3. J+12 : dernière relance, avec un angle différ	ent (exemple de réalisation, prix indicatif).
La séquence s’arrête automatiquement dès qu’il y a une réponse, un clic sur « désinscription » ou
un rebond. Détection des réponses par lecture de la boîte (IMAP), y compris les réponses
automatiques d’absence à ignorer.

Module 5 — Délivrabilité (le facteur n°1 de réussite)
Un bon email qui arrive en spam ne sert à rien. À intégrer dès la V1 :
Domaine d’envoi dédié (ex. codenyr-web.fr ou contact.codenyr.fr) pour protéger codenyr.fr.
SPF, DKIM, DMARC configur és ; page de diagnostic dans l’outil qui vérifie les DNS.
Préchauffage (warm-up) : commencer à ~10 envois/jour, monter progressivement sur 3-4
semaines.
Plafond d’envoi quotidien et horaire réglables, espacement aléatoire entre deux emails (ex. 2
à 6 min).
Créneaux d’envoi : jours ouvrés, 8h-11h et 14h-17h, jamais le week-end.
Fournisseur SMTP qui accepte la prospection B2B (vérifier les CGU : beaucoup d’emailing «
newsletter » l’interdisent) ou une boîte Google Workspace / OVH dédiée.
Gestion des rebonds : rebond dur = adresse blacklistée ; seuil d’alerte si le taux de rebond
dépasse 3 %.
Coupe-circuit : pause automatique de toutes les campagnes si rebonds ou plaintes anormaux.
Surveillance du domaine sur les listes noires (MXToolbox ou équivalent).
Module 6 — Mini-CRM, suivi et statistiques
Statuts d’un prospect : Nouveau → Qualifié → Contacté → R	elancé → A répondu → Devis envoyé →
Gagné / Perdu, plus Désinscrit, Rebond, Ne pas contacter.
Fiche prospect : infos entreprise, capture du site, problèmes détectés, score, historique des emails
envoyés et reçus, notes libres, date de prochaine action, montant du devis.
Tableau de bord :
Entonnoir : trouvés → avec email → contactés → réponses → devis → signés.
Taux de réponse et de conversion par template, par secteur, par zone (pour savoir quoi cibler).
Clics sur le bouton codenyr.fr (via UTM dans ton outil d’analyse, plutôt qu’un suivi individuel par
prospect, plus prudent côté CNIL).
Santé de l’envoi : rebonds, désinscriptions, volume du jour.
Chiffr e d’affair es généré par l’outil (ROI).
Idées bonus
Mini-audit gratuit personnalisé : une page web générée par prospect (lien unique, pas de
pièce jointe) qui montre la capture de leur site, les problèmes détectés et le score PageSpeed.
Très convaincant.
Maquette « avant / après » : générer une maquette rapide de page d’accueil avec leur nom
et leur secteur pour les prospects à fort score.
Rédaction assistée par IA : générer une phrase d’accroche personnalisée par prospect (à
relire avant envoi), via un modèle local gratuit (Ollama) pour rester à 0 €.

Export courrier postal : publipostage PDF d’une lettre pour les prospects sans email, avec
l’adresse Sirene.
Liste d’appels du jour : prospects sans email mais avec téléphone, avec un script d’appel et
un bouton « résultat de l’appel ».
Veille création d’entreprises : alerte chaque semaine sur les nouvelles immatriculations de
ta zone (elles n’ont presque jamais de site).
Prise de rendez-vous : lien Calendly / Cal.com dans l’email en plus du bouton.
Notifications : email ou Telegram quand un prospect répond.
Réponses types pour gérer vite les retours (« intéressé », « pas maintenant », « déjà un
prestataire ») et relance auto à 6 mois pour « pas maintenant ».
Sauvegarde automatique de la base (spatie/laravel-backup).
Stack technique et modèle de données
Brique	Choix proposé	Pourquoi
Framework	Laravel (dernière version stable) Ta stack
Interface admin	Filament	Tableaux, filtres, formulaires et
dashboard sans écrire de front
Base de données	MySQL ou PostgreSQL	Standard
Tâches de fond	Queues Laravel + Redis, Horizon
pour surveiller
Recherches, crawl et envois sont
longs
Planification	Scheduler Laravel (cron) Relances, recherches récurrentes,
purge
Requêtes HTTP / crawl	Client HTTP Laravel, symfony/dom-
crawler
Analyse des sites
Captures	spatie/browsershot (Puppeteer) Screenshots des sites
Emails	Mailables Laravel + SMTP dédié,
webklex/laravel-imap pour les
réponses
Envoi et détection de réponses
Éditeur de templates	Markdown ou éditeur riche
Filament + variables
Rédaction simple
Hébergement	VPS (Hetzner, OVH…) avec cron et
Redis
Doit tourner en continu
Tables principales :
Table	Contenu clé
companies	siren, nom, naf, adresse, ville, téléphone, effectif,
date_creation, statut, score
websites	company_id, url, https, responsive, pagespeed,
copyright_year, technos, problemes (JSON), capture

Table	Contenu clé
contacts	company_id, email, type (générique/nominatif),
source, collecté_le, vérifié
searches	critères (JSON), récurrence, dernier_lancement
templates	nom, angle, objet, corps, variante A/B
sequences / sequence_steps	délais, template par étape
campaigns	recherche ou liste ciblée, séquence, quotas
emails	contact_id, campagne, étape, envoyé_le, statut
(envoyé, rebond, répondu), message_id
suppression_list	email ou domaine, raison, date
notes / activities	historique libre et actions
Sécurité : même pour un outil perso, authentification avec 2F	A (il contient des données personnelles)
et clés API dans le .env, jamais dans le code.
Découpage en versions et questions ouvertes
MVP (valider que ça rapporte des devis)
1. Import CSV + recherche via l’API Recherche d’entreprises.
2. Analyse du site (HTTPS, responsive, copyright, PageSpeed) et score.
3. Extraction d’email depuis le site + vérification MX.
4. Deux templates (sans site / site obsolète), envoi en file d’attente avec quotas.
5. Désinscription, liste d’opposition, mentions légales.
6. Statuts manuels des prospects.
V2 (automatiser)
1. OpenStreetMap, devinette de domaine, recherches récurrentes, captures d’écran.
2. Séquences de relance + détection des réponses IMAP.
3. Dashboard et A/B test.
V3 (convertir plus)
1. Mini-audit personnalisé, accroche IA, maquette avant/après.
2. Export courrier postal et liste d’appels.
3. Veille des créations d’entreprises.
Questions à trancher :
☑ Zone : Bourgogne (Côte-d’Or 21, Nièvre 58, Saône-et-Loire 71, Yonne 89).
☑ Secteurs : artisans du bâtiment, restauration et commerces, beauté et bien-être, professions
libérales (un preset de codes NAF par secteur).
☑ Volume : 100 à 150 emails par semaine, atteint progressivement après le préchauffage.

☑ Prospects sans email : liste d’appels (le courrier postal reste une idée bonus).
☑ Prix : affichage « site vitrine à partir de X € ».
Montant du prix d’appel à indiquer.
Domaine d’envoi dédié ou sous-domaine, et hébergement VPS ou local (voir le document «
Ce que tu dois faire toi-même »).
Réalisations à montrer par secteur.
Temps disponible par semaine pour les réponses et devis.
Plan — 12 missions pour l’IA
Le MVP et l’essentiel de la V2 tiennent en 12 missions, à réaliser dans l’ordre : une mission ne
commence que lorsque la précédente est validée par ses tests.
Règles de travail données à l’IA (à mettre dans un fichier 	CLAUDE.md à la racine du projet) :
Une mission par session. Lire ce cahier des charges et le CLAUDE.md avant de commencer.
Tests écrits avec Pest. Aucun appel réel à une API ou à un serveur mail dans les tests
(Http::fake(), Mail::fake(), Queue::fake()).
Une mission est terminée quand : php artisan test est entièrement vert, Pint ne signale rien, et
tu as fait les vérifications manuelles de la mission.
Un commit (ou une branche + merge) par mission, avec le numéro de mission dans le message.
Ne jamais modifier le code d’une mission validée sans le signaler et r	elancer toute la suite de
tests.
À faire toi-même avant M1 : acheter le domaine d’envoi, configur	er SPF/DKIM/DMARC et démarrer
le préchauffage de la boîte. L e warm-up prend 3 à 4 semaines, il doit commencer tout de suite pour
pouvoir envoyer à partir de M9.
Mission	Résultat attendu
M1 — Socle du projet	Projet Laravel + Filament accessible avec connexion
2FA ; queues, Horizon et CI fonctionnent
M2 — Modèle de données	Toutes les tables existent ; des données de démo sont
consultables et filtrables dans Filament
M3 — Import CSV	Un fichier CSV s’importe sans doublon, avec un
rapport des lignes en erreur
M4 — Recherche d’entreprises	Une recherche « Bourgogne + secteur » remplit la
base automatiquement via les API gratuites et
OpenStreetMap
M5 — Analyse des sites	Chaque entreprise a un état de site (aucun, réseau
social, hors ligne, actif) et une liste de problèmes
détectés
M6 — Scoring	Les prospects sont notés de 0 à 100 et les meilleurs
apparaissent en tête de liste

Mission	Résultat attendu
M7 — Emails de contact	Les emails sont trouvés, classés et vérifiés (MX), avec
leur source ; les prospects sans email vont dans la
liste d’appels
M8 — Templates	Des emails personnalisés se prévisualisent avec un
vrai prospect et contiennent toutes les mentions
obligatoires
M9 — Envoi conforme	Un premier email réel arrive en boîte de réception ;
quotas, créneaux et liste d’opposition sont respectés
M10 — Relances et réponses	La séquence J0 / J+4 / J+12 tourne seule et s’arrête
sur réponse, désinscription ou rebond
M11 — Dashboard	L’entonnoir et les taux par template, secteur et zone
sont visibles ; le coupe-circuit est actif
M12 — Mise en production	L’outil tourne seul sur son hébergement, est
sauvegardé, et la campagne pilote de 10 envois est
lancée
M1 — Socle du projet
Objectif : projet Laravel prêt à recevoir les fonctionnalités. Filament installé, authentification avec
2FA, Redis + queues + Horizon, Pest, Pint, CI GitHub Actions, .env.example complet.
Tests automatiques :
Un visiteur non connecté est redirigé vers la connexion sur toutes les pages admin.
Un utilisateur connecté accède au tableau de bord Filament.
Un job de test envoyé en file est bien e	xécuté.
La CI exécute les tests et Pint à chaque push.
Vérifications manuelles :
Connexion avec 2FA fonctionnelle depuis ton téléphone.
Horizon accessible et protégé par la connexion.
Aucun secret dans le dépôt Git.
M2 — Modèle de données
Objectif : migrations, modèles, relations, factories et seeders pour toutes les tables de la section
Stack (companies, websites, contacts, searches, templates, sequences, campaigns, emails,
suppression_list, activities).
Tests automatiques :
migrate:fresh --seed passe sans erreur.
Chaque relation (entreprise → site, contacts, emails…) est testée.
Le SIREN est unique : créer un doublon échoue.
Une adresse de la liste d’opposition est détectée, quelle que soit la casse.

Les statuts d’un prospect n’acceptent que les valeurs prévues (enum PHP).
Vérifications manuelles :
Les entreprises, contacts et la liste d’opposition sont consultables et filtrables dans F	ilament.
Le schéma correspond au cahier des charges (relire les migrations).
M3 — Import CSV et dédoublonnage
Objectif : importer un fichier CSV depuis F	ilament, avec choix des colonnes, traitement en tâche de
fond et rapport d’import.
Tests automatiques :
Un fichier de 100 lignes valides crée 100 entreprises.
Les doublons (même SIREN, puis même domaine ou téléphone) sont fusionnés et non r ecréés.
Les lignes invalides sont listées dans le rapport, sans bloquer l’import.
Un email présent dans la liste d’opposition est importé mais marqué « Ne pas contacter ».
La source et la date de collecte sont enregistrées pour chaque contact.
Vérifications manuelles :
Import d’un vrai fichier de 20 entr	eprises que tu connais, résultat contrôlé à la main.
Réimporter le même fichier ne cr	ée aucun doublon.
M4 — Recherche via l’API Recherche d’entreprises
Objectif : formulaire de recherche (commune ou département, codes NAF, tranche d’effectif, date de
création), recherches sauvegardables, import des résultats en tâche de fond, puis enrichissement
gratuit via OpenStreetMap (Overpass) et devinette de domaine.
Tests automatiques (réponses d’API simulées) :
Les filtres sont bien traduits en paramètres de requête.
La pagination récupère toutes les pages de résultats.
Les entreprises fermées ou en procédure collective sont exclues.
Une erreur ou un dépassement de limite de l’API déclenche une nouvelle tentative, sans planter
le job.
Relancer une recherche n’ajoute que les nouvelles entreprises.
Vérifications manuelles :
Une vraie recherche sur ta commune et un secteur (ex. plombiers) donne des résultats
cohérents.
Comparer 5 résultats avec annuaire-entreprises.data.gouv.fr.
M5 — Analyse des sites
Objectif : pour chaque entreprise, déterminer l’état du site (aucun, réseau social seul, hors ligne,
parking, actif) puis analyser HTTPS, balise viewport, année du copyright, technologies (meta

generator, version de jQuery), mentions légales, formulaire de contact, score PageSpeed. Résultat
stocké dans websites, avec la liste des problèmes en clair.
Tests automatiques (avec des pages HTML d’exemple dans tests/Fixtures) :
Un site moderne ne remonte aucun problème majeur.
Un site sans viewport, en HTTP, avec « © 2012 » remonte les trois problèmes.
Un site hors ligne, un délai dépassé ou un certificat e	xpiré sont gérés sans erreur.
Une page parking et une URL Facebook sont classées correctement.
Les redirections (http → https, www) sont suivies.
Vérifications manuelles :
Analyse de 10 sites que tu connais (dont 3 récents faits par toi) : le diagnostic correspond à ton
avis d’expert.
M6 — Scoring et priorisation
Objectif : score de 0 à 100 configurable (poids r	églables dans les paramètres), recalculé après
chaque analyse ; liste Filament triée par score.
Tests automatiques :
Des profils types donnent le score attendu (ex. sans site + entreprise de 5 ans avec téléphone →
score élevé ; site récent → score bas).
Modifier un poids r ecalcule les scores.
Une entreprise « Ne pas contacter » n’apparaît jamais dans la liste à traiter.
Vérifications manuelles :
Les 20 premiers de la liste sont des prospects que tu aurais réellement envie de contacter.
M7 — Recherche et vérification des emails
Objectif : crawl de l’accueil, de la page contact et des mentions légales (respect de robots.txt, délai
entre requêtes), extraction des emails, classement générique / nominatif / personnel, vérification
syntaxe + MX, ajout des prospects sans email mais avec téléphone à la liste d’appels.
Tests automatiques (pages d’exemple) :
Un lien mailto: et un email écrit en clair sont extraits.
Les formes masquées courantes (« contact [at] domaine.fr ») sont reconnues.
Une page interdite par robots.txt n’est pas visitée.
Un domaine sans MX est marqué invalide.
contact@ est classé générique, une adresse gmail.com est classée personnelle et mise en revue
manuelle.
La source et la date de collecte sont enregistrées.
Vérifications manuelles :

Sur 20 sites réels, comparer les emails trouvés à ceux visibles à l’œil : au moins 80 % retrouvés,
aucun faux email.
M8 — Templates et personnalisation
Objectif : éditeur de templates dans Filament (objet, corps, angle « sans site » / « site obsolète »),
variables, version texte brut générée, aperçu avec un vrai prospect, bouton vers codenyr.fr avec UTM,
pied de page légal automatique.
Tests automatiques :
Toutes les variables sont remplacées ; une variable vide utilise sa valeur de secours ou bloque
l’envoi.
Chaque email rendu contient : identité Codenyr, lien de désinscription, lien confidentialité,
bouton avec UTM.
Le rendu ne contient aucun pixel ou image de suivi.
La version texte brut existe et reprend le lien.
La phrase « problèmes détectés » liste bien les problèmes du site analysé (M5).
Vérifications manuelles :
Relire 5 aperçus réels : ton naturel, pas d’erreur de variable, moins de 150 mots.
Affichage cor rect sur Gmail, Outlook et mobile (envoi vers tes propres boîtes).
M9 — Envoi conforme
Objectif : campagne = liste ciblée + template ; envoi par file d’attente avec plafond quotidien,
espacement aléatoire, créneaux ouvrés ; désinscription en un clic ; en-têtes List-Unsubscribe ; liste
d’opposition appliquée au dernier moment avant chaque envoi.
Tests automatiques (Mail::fake()) :
Le plafond quotidien n’est jamais dépassé, l’excédent part le jour ouvré suivant.
Rien ne part le week-end ni hors créneau.
Une adresse désinscrite après la mise en file n’est pas envoyée.
Le lien de désinscription fonctionne sans connexion, est signé (non devinable) et ajoute
l’adresse à la liste d’opposition.
Les en-têtes List-Unsubscribe et List-Unsubscribe-Post sont présents.
Une entreprise n’est jamais contactée deux fois par la même campagne.
Vérifications manuelles :
Envoi vers mail-tester.com : score d’au moins 9/10.
Envoi vers tes boîtes Gmail et Outlook : arrivée en boîte de réception, pas en spam.
Clic sur « se désinscrire » depuis Gmail : l’adresse apparaît dans la liste d’opposition.

M10 — Relances, réponses et rebonds
Objectif : séquences J0 / J+4 / J+12 en jours ouvrés ; lecture IMAP de la boîte d’envoi pour détecter
réponses, réponses automatiques et rebonds ; arrêt automatique de la séquence ; notification quand
un prospect répond.
Tests automatiques (messages IMAP simulés) :
Sans réponse, la relance part au bon jour ouvré avec le bon template.
Une réponse (reconnue par In-Reply-To / References) arrête la séquence et passe le prospect
en « A répondu ».
Une réponse d’absence automatique n’arrête pas la séquence.
Un rebond dur passe l’adresse en invalide et l’ajoute à la liste d’opposition ; un rebond
temporaire est retenté une fois.
Une désinscription en cours de séquence annule les relances restantes.
Vérifications manuelles :
Scénario complet avec 3 de tes adresses : une qui répond, une qui ne répond pas, une invalide.
M11 — Dashboard et coupe-circuit
Objectif : tableau de bord Filament (entonnoir trouvés → avec email → contactés → réponses → devis
→ signés, taux par template / secteur / zone, rebonds et désinscriptions, CA signé) ; coupe-circuit qui
met tout en pause si les rebonds dépassent 3 % ou les désinscriptions 2 % sur 7 jours.
Tests automatiques :
Sur un jeu de données connu, chaque chiffr	e de l’entonnoir est exact.
Le taux de réponse par template est correct, A/B compris.
Le coupe-circuit se déclenche au-delà du seuil, met les campagnes en pause et te notifie.
La reprise manuelle relance les envois.
Vérifications manuelles :
Le dashboard se lit en moins de 30 secondes et répond à « qu’est-ce qui marche ? ».
M12 — RGPD, sauvegarde et mise en production
Objectif : purge automatique des prospects sans réponse après 3 ans ; export des données d’une
personne sur demande ; sauvegardes quotidiennes ; déploiement sur VPS (HTTPS, cron du scheduler,
Horizon supervisé) ; page « Registre » décrivant le traitement.
Tests automatiques :
La purge supprime les prospects inactifs de plus de 3 ans mais conserve la liste d’opposition.
L’export d’une personne contient toutes ses données et leur source.
Le scheduler déclare bien toutes les tâches prévues (relances, purge, sauvegarde, recherches
récurrentes).

Vérifications manuelles :
Restaurer une sauvegarde sur une base de test : tout est là.
Redémarrer le serveur : queues et scheduler repartent seuls.
Campagne pilote : 10 vrais prospects bien notés, envoi réel, suivi 1 semaine avant
d’augmenter le volume.
Après M12 : l’outil tourne seul. Les idées bonus (mini-audit, accroche IA, courrier postal, veille des
créations) deviennent les missions suivantes (M13 et plus).
```
