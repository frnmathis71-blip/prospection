<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;

/**
 * BODACC (annonces commerciales officielles) : repère les entreprises qui ont fait l'objet
 * d'une procédure collective récente (sauvegarde, redressement, liquidation).
 * L'API Recherche d'entreprises ne fournit pas cette information.
 */
class BodaccClient
{
    /**
     * SIREN ayant au moins une annonce de procédure collective sur la période de référence.
     *
     * @param  list<string>  $sirens
     * @return list<string>
     *
     * @throws ApiUnavailableException
     */
    public function sirensWithCollectiveProcedure(array $sirens): array
    {
        $sirens = array_values(array_unique(array_filter($sirens, fn ($siren) => preg_match('/^\d{9}$/', (string) $siren))));

        if ($sirens === []) {
            return [];
        }

        $since = now()->subYears((int) config('prospection.bodacc.lookback_years', 3))->toDateString();
        $list = implode(', ', array_map(fn (string $siren) => '"'.$siren.'"', $sirens));

        try {
            $response = ExternalHttp::client((int) config('prospection.bodacc.timeout', 20))
                ->get(config('prospection.bodacc.url'), [
                    'select' => 'registre',
                    'where' => "familleavis = \"collective\" AND dateparution >= date'{$since}' AND registre IN ({$list})",
                    'limit' => 100,
                ]);
        } catch (ConnectionException $exception) {
            throw new ApiUnavailableException('BODACC injoignable : '.$exception->getMessage());
        }

        ExternalHttp::ensureAvailable($response, 'BODACC');
        $response->throw();

        return collect($response->json('results', []))
            ->flatMap(fn (array $record) => (array) ($record['registre'] ?? []))
            ->map(fn (string $value) => preg_replace('/\s+/', '', $value))
            ->intersect($sirens)
            ->unique()
            ->values()
            ->all();
    }
}
