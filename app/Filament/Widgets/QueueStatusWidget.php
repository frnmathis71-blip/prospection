<?php

namespace App\Filament\Widgets;

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

    protected ?string $pollingInterval = '5s';

    protected ?string $heading = 'Traitements en tâche de fond';

    /** Au-delà de ce délai, une tâche en attente signale un worker arrêté. */
    public const STALE_AFTER_SECONDS = 60;

    protected function getStats(): array
    {
        [$pending, $oldest] = $this->pendingJobs();
        $failed = $this->failedJobs();
        $stale = $oldest !== null && $oldest->diffInSeconds(now()) > self::STALE_AFTER_SECONDS;

        return [
            Stat::make('Tâches en attente', $pending ?? '—')
                ->description(match (true) {
                    $pending === null => 'File d’attente injoignable',
                    $stale => 'En attente depuis '.$oldest->locale('fr')->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE),
                    $pending > 0 => 'En cours de traitement',
                    default => 'Rien à traiter',
                })
                ->descriptionIcon($pending ? Heroicon::OutlinedClock : Heroicon::OutlinedCheckCircle)
                ->color($pending === null || $stale ? 'danger' : ($pending > 0 ? 'warning' : 'success')),
            Stat::make('Worker', match (true) {
                $pending === null => 'Inconnu',
                $stale => 'Arrêté ?',
                default => 'OK',
            })
                ->description($stale || $pending === null
                    ? 'Lancez « php artisan queue:work » (Horizon en production)'
                    : 'Les tâches sont traitées')
                ->descriptionIcon($stale ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($stale || $pending === null ? 'danger' : 'success'),
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
            $pending = $queue->size();
            $oldest = method_exists($queue, 'creationTimeOfOldestPendingJob') ? $queue->creationTimeOfOldestPendingJob() : null;

            return [$pending, $oldest ? Carbon::createFromTimestamp($oldest) : null];
        } catch (Throwable) {
            return [null, null];
        }
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
