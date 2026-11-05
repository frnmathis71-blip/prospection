<?php

/*
 * Point d'entrée de la tâche planifiée OVH (hébergement mutualisé), lancée une fois par heure.
 * OVH n'accepte qu'un chemin de script, sans arguments : ce fichier lance la commande
 * « php artisan app:shared-hosting-cron » (planificateur chaque minute + file d'attente, 55 min).
 *
 * Espace client OVH → Hébergements → Tâches planifiées - Cron → Ajouter :
 *   Commande : prospection/cron-ovh.php   ·   Langage : PHP 8.4   ·   Fréquence : toutes les heures
 */

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

exit($app->handleCommand(new ArgvInput(['artisan', 'app:shared-hosting-cron'])));
