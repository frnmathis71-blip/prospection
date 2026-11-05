<?php

use App\Models\User;
use App\Services\ProductionCheck;
use App\Support\Periodic;
use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

function parisAt(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, 'Europe/Paris'));
}

describe('tâches « une fois par période »', function () {
    it('lance une tâche quotidienne une seule fois, au premier passage après l’heure prévue', function () {
        $due = Periodic::daily('test-backup', '01:30');

        parisAt('2026-11-09 01:29');
        expect($due())->toBeFalse();

        parisAt('2026-11-09 01:47'); // la minute 01:30 a été manquée (boucle arrêtée)
        expect($due())->toBeTrue();

        parisAt('2026-11-09 01:48');
        expect($due())->toBeFalse();

        parisAt('2026-11-10 01:31');
        expect($due())->toBeTrue();
    });

    it('lance une tâche hebdomadaire le lundi après 7 h, une fois par semaine', function () {
        $due = Periodic::weekly('test-weekly', 1, '07:00');

        parisAt('2026-11-09 06:59'); // lundi
        expect($due())->toBeFalse();
        parisAt('2026-11-09 07:12');
        expect($due())->toBeTrue();
        parisAt('2026-11-11 10:00');
        expect($due())->toBeFalse();
        parisAt('2026-11-16 07:05');
        expect($due())->toBeTrue();
    });

    it('lance une tâche mensuelle le 1er après 7 h, une fois par mois', function () {
        $due = Periodic::monthly('test-monthly', 1, '07:00');

        parisAt('2026-12-01 06:00');
        expect($due())->toBeFalse();
        parisAt('2026-12-01 09:00');
        expect($due())->toBeTrue();
        parisAt('2026-12-15 09:00');
        expect($due())->toBeFalse();
        parisAt('2027-01-01 07:00');
        expect($due())->toBeTrue();
    });

    it('lance une tâche horaire une fois par heure', function () {
        $due = Periodic::hourly('test-hourly');

        parisAt('2026-11-09 10:05');
        expect($due())->toBeTrue();
        parisAt('2026-11-09 10:59');
        expect($due())->toBeFalse();
        parisAt('2026-11-09 11:01');
        expect($due())->toBeTrue();
    });
});

describe('boucle horaire du mutualisé', function () {
    it('lance le planificateur chaque minute puis traite la file jusqu’à la minute suivante', function () {
        $ran = [];
        Process::fake(function (PendingProcess $process) use (&$ran) {
            $ran[] = implode(' ', array_slice($process->command, 1));

            return Process::result();
        });
        Sleep::fake(syncWithCarbon: true);
        parisAt('2026-11-09 10:00:10');

        $this->artisan('app:shared-hosting-cron --minutes=3')->expectsOutputToContain('4 passage(s)')->assertSuccessful(); // 10:00:10, 10:01, 10:02, 10:03

        $commands = collect($ran);

        expect($commands->filter(fn ($command) => $command === 'artisan schedule:run'))->toHaveCount(4)
            ->and($commands->filter(fn ($command) => str_starts_with($command, 'artisan queue:work --queue=default,enrichment --stop-when-empty')))->toHaveCount(4)
            ->and($commands->get(1))->toContain('--max-time=50') // de 10:00:10 à 10:01:00
            ->and($commands->last())->toContain('--max-time=10'); // de 10:03:00 à la fin, 10:03:10
    });

    it('ne lance jamais deux boucles en même temps', function () {
        Process::fake();
        Cache::lock('shared-hosting-cron', 600)->get();

        $this->artisan('app:shared-hosting-cron --minutes=1')->expectsOutputToContain('déjà en cours')->assertSuccessful();

        Process::assertNothingRan();
    });

    it('continue la boucle même si une étape échoue', function () {
        Process::fake(['*schedule:run*' => Process::result(errorOutput: 'Erreur de test', exitCode: 1), '*' => Process::result()]);
        Sleep::fake(syncWithCarbon: true);
        parisAt('2026-11-09 10:00:00');

        $this->artisan('app:shared-hosting-cron --minutes=2')
            ->expectsOutputToContain('schedule:run : Erreur de test')
            ->expectsOutputToContain('2 passage(s)')
            ->assertSuccessful();

        Process::assertRanTimes(fn (PendingProcess $process) => in_array('queue:work', $process->command, true), 2);
    });
});

describe('configuration du mutualisé', function () {
    it('accepte la file et le cache en base sur un mutualisé', function () {
        config(['prospection.hosting' => 'shared', 'queue.default' => 'database', 'cache.default' => 'database']);

        expect(app(ProductionCheck::class)->problems())->not->toContain('Mutualisé : QUEUE_CONNECTION doit valoir « database ».')
            ->not->toContain('QUEUE_CONNECTION doit valoir « redis » (Horizon), ou HOSTING_MODE=shared sur un mutualisé.');

        config(['queue.default' => 'redis', 'cache.default' => 'redis']);
        expect(app(ProductionCheck::class)->problems())->toContain(
            'Mutualisé : QUEUE_CONNECTION doit valoir « database ».',
            'Mutualisé : CACHE_STORE doit valoir « database » (pas de Redis).',
        );
    });

    it('ferme Horizon sur un mutualisé (pas de Redis)', function () {
        $user = User::factory()->withAppAuthentication()->create();

        expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();

        config(['prospection.hosting' => 'shared']);
        expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
    });

    it('a un point d’entrée pour la tâche planifiée OVH', function () {
        expect(file_get_contents(base_path('cron-ovh.php')))->toContain("new ArgvInput(['artisan', 'app:shared-hosting-cron'])");
    });
});
