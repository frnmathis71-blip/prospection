<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

/**
 * Hébergement mutualisé (OVH) : une seule tâche planifiée par heure, arrêtée au bout de 60 min.
 * Cette commande la rentabilise : pendant 55 minutes, elle lance le planificateur chaque minute
 * (`schedule:run`) puis traite la file d'attente (`queue:work`) jusqu'à la minute suivante,
 * comme le feraient un cron toutes les minutes et un worker permanent sur un VPS.
 *
 * Chaque étape tourne dans son propre processus PHP (mémoire libérée, une erreur n'arrête pas la
 * boucle). Un verrou empêche deux boucles de tourner en même temps.
 */
class SharedHostingCron extends Command
{
    protected $signature = 'app:shared-hosting-cron {--minutes=55 : Durée de la boucle (OVH arrête la tâche à 60 min)}';

    protected $description = 'Hébergement mutualisé : planificateur chaque minute + file d’attente, pendant 55 minutes';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $lock = Cache::lock('shared-hosting-cron', ($minutes + 3) * 60);

        if (! $lock->get()) {
            $this->warn('Une boucle est déjà en cours : rien à faire.');

            return self::SUCCESS;
        }

        try {
            $end = now()->addMinutes($minutes);
            $iterations = 0;

            while (now()->lt($end)) {
                $nextMinute = now()->startOfMinute()->addMinute();

                $this->artisan(['schedule:run'], timeout: 300);

                // File d'attente jusqu'à la minute suivante (sans dépasser la fin de la boucle).
                $budget = (int) min(now()->diffInSeconds($nextMinute, false), now()->diffInSeconds($end, false));

                if ($budget >= 5) {
                    $this->artisan([
                        'queue:work', '--queue=default,enrichment', '--stop-when-empty',
                        "--max-time={$budget}", '--sleep=1', '--tries=3',
                    ], timeout: $budget + 960); // un job commencé peut finir (recherche : 900 s au plus)
                }

                $iterations++;

                if (now()->lt($nextMinute) && now()->lt($end)) {
                    Sleep::until($nextMinute);
                }
            }

            $this->info("{$iterations} passage(s) du planificateur et de la file d’attente.");
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function artisan(array $arguments, int $timeout): void
    {
        $result = Process::path(base_path())->timeout($timeout)->run([PHP_BINARY, 'artisan', ...$arguments]);

        if ($result->failed()) {
            // Sortie limitée à 5 Mo sur OVH : seulement l'essentiel.
            $this->error(implode(' ', $arguments).' : '.mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 500));
        }
    }
}
