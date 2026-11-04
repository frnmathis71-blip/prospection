<?php

use Symfony\Component\Yaml\Yaml;

it('lance Pint et les tests à chaque push', function () {
    $workflow = Yaml::parseFile(base_path('.github/workflows/ci.yml'));

    // La clé « on » est lue comme booléen true par le parseur YAML 1.1.
    $triggers = $workflow['on'] ?? $workflow[true] ?? [];

    $commands = collect($workflow['jobs'])
        ->flatMap(fn (array $job) => $job['steps'])
        ->pluck('run')
        ->filter()
        ->implode("\n");

    expect($triggers)->toHaveKey('push')
        ->and($commands)->toContain('vendor/bin/pint --test')
        ->and($commands)->toContain('php artisan test');
});
