<?php

namespace App\Filament\Widgets;

use App\Jobs\EnrichCompany;
use App\Services\OverpassPause;
use App\Support\WorkerHeartbeat;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Tableau de bord : état de la file d'attente (tâches en attente, échouées, worker actif ou non).
 */
class QueueStatusWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    // Affiché dès l'ouverture du tableau de bord (pas de chargement différé).
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '5s';

    protected ?string $heading = 'Traitements en tâche de fond';

    /** Sans worker actif, une tâche qui attend depuis plus longtemps signale un worker arrêté. */
    public const STALE_AFTER_SECONDS = 60;

    private const START_WORKER_HINT = 'Lancez « php artisan queue:work --queue=default,enrichment » dans le dossier du projet (Horizon en production)';

    protected function getStats(): array
    {
        [$pending, $oldest] = $this->pendingJobs();
        $failed = $this->failedJobs();
        $alive = WorkerHeartbeat::isAlive();
        $stale = ! $alive && $oldest !== null && $oldest->diffInSeconds(now()) > self::STALE_AFTER_SECONDS;

        return [
            Stat::make('Tâches en attente', $pending ?? '—')
                ->description(match (true) {
                    $pending === null => 'File d’attente injoignable',
                    $stale => 'En attente depuis '.$oldest->locale('fr')->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE),
                    $pending > 0 && $alive => 'Traitement en cours (débit limité pour les enrichissements)'.self::overpassNotice(),
                    $pending > 0 => 'En attente du worker',
                    default => 'Rien à traiter',
                })
                ->descriptionIcon($pending ? Heroicon::OutlinedClock : Heroicon::OutlinedCheckCircle)
                ->color($pending === null || $stale ? 'danger' : ($pending > 0 ? 'warning' : 'success')),
            Stat::make('Worker', match (true) {
                $pending === null => 'Inconnu',
                $alive => 'Actif',
                $stale => 'Arrêté',
                default => 'Non détecté',
            })
                ->description(match (true) {
                    $alive => 'Dernier signal '.WorkerHeartbeat::last()->locale('fr')->diffForHumans(),
                    default => self::START_WORKER_HINT,
                })
                ->descriptionIcon($alive ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                ->color(match (true) {
                    $alive => 'success',
                    $stale || $pending === null => 'danger',
                    default => 'warning',
                }),
            Stat::make('Tâches échouées', $failed)
                ->description($failed > 0 ? 'Détail dans Horizon ou « php artisan queue:failed »' : 'Aucun échec')
                ->descriptionIcon($failed > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($failed > 0 ? 'danger' : 'success'),
        ];
    }

    /**
     * @return array{0: ?int, 1: ?Carbon} nombre de tâches en attente et date de la plus ancienne
     */
    private function pendingJobs(): array
    {
        try {
            $queue = Queue::connection();
            $pending = 0;
            $oldest = null;

            // File principale (imports, recherches) + file des enrichissements.
            foreach (['default', EnrichCompany::QUEUE] as $name) {
                $pending += $queue->size($name);
                $created = method_exists($queue, 'creationTimeOfOldestPendingJob') ? $queue->creationTimeOfOldestPendingJob($name) : null;
                $oldest = $created !== null ? min($oldest ?? $created, $created) : $oldest;
            }

            return [$pending, $oldest ? Carbon::createFromTimestamp($oldest) : null];
        } catch (Throwable) {
            return [null, null];
        }
    }

    private static function overpassNotice(): ?string
    {
        $until = OverpassPause::until();

        return $until ? ' · OpenStreetMap saturé, reprise vers '.$until->timezone(config('app.timezone'))->format('H:i') : null;
    }

    private function failedJobs(): int
    {
        try {
            return DB::table(config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
