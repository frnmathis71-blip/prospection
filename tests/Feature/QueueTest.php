<?php

use App\Jobs\QueueHealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

it('exécute un job envoyé en file', function () {
    // Le driver « database » remplace Redis dans les tests : même cycle
    // mise en file → worker → exécution, sans serveur externe.
    config(['queue.default' => 'database']);

    QueueHealthCheck::dispatch('jeton-de-test');

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(Cache::get(QueueHealthCheck::CACHE_KEY))->toBeNull();

    $this->artisan('queue:work', ['--once' => true, '--queue' => 'default'])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(Cache::get(QueueHealthCheck::CACHE_KEY))->toBe('jeton-de-test');
});

it('la commande de contrôle met le job en file', function () {
    Queue::fake();

    $this->artisan('app:queue-check')->assertSuccessful();

    Queue::assertPushed(QueueHealthCheck::class);
});

it('utilise Redis comme file par défaut hors tests', function () {
    expect(file_get_contents(base_path('.env.example')))
        ->toContain('QUEUE_CONNECTION=redis')
        ->and(config('horizon.use'))->toBe('default');
});
