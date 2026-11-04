<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;

/**
 * API Recherche d'entreprises (recherche-entreprises.api.gouv.fr), gratuite et sans clé.
 */
class RechercheEntreprisesClient
{
    /**
     * Une page de résultats.
     *
     * @return array{results: list<array<string, mixed>>, total_results: int, page: int, total_pages: int}
     *
     * @throws ApiUnavailableException
     */
    public function search(SearchCriteria $criteria, int $page = 1): array
    {
        try {
            $response = ExternalHttp::client((int) config('prospection.recherche_entreprises.timeout', 20))
                ->get(rtrim(config('prospection.recherche_entreprises.url'), '/').'/search', $criteria->toQuery($page));
        } catch (ConnectionException $exception) {
            throw new ApiUnavailableException('API Recherche d’entreprises injoignable : '.$exception->getMessage());
        }

        ExternalHttp::ensureAvailable($response, 'API Recherche d’entreprises');
        $response->throw(); // 4xx : critères refusés par l'API, erreur à corriger (pas de nouvelle tentative)

        return [
            'results' => $response->json('results') ?? [],
            'total_results' => (int) $response->json('total_results', 0),
            'page' => (int) $response->json('page', $page),
            'total_pages' => (int) $response->json('total_pages', 0),
        ];
    }
}
