<?php

namespace App\Services;

use App\Support\CompanyName;
use Illuminate\Http\Client\ConnectionException;

/**
 * OpenStreetMap via l'API Overpass (gratuite, usage raisonnable).
 * Données © contributeurs OpenStreetMap, licence ODbL.
 */
class OverpassClient
{
    /**
     * Lieu OSM le plus probable pour l'entreprise : un lieu nommé à proximité dont le nom
     * partage au moins un mot distinctif avec celui de l'entreprise.
     *
     * @return array<string, string>|null tags OSM du lieu retenu
     *
     * @throws ApiUnavailableException
     */
    public function findPlace(string $name, float $latitude, float $longitude): ?array
    {
        if (CompanyName::distinctiveWords($name) === []) {
            return null; // nom trop générique (« BOULANGERIE ») : aucun rapprochement fiable
        }

        // Serveur saturé récemment : on n'envoie rien, le job reprendra après la pause commune.
        if (OverpassPause::isActive()) {
            throw new ApiUnavailableException(
                'OpenStreetMap (Overpass) en pause jusqu’à '.OverpassPause::until()->format('H:i').' (serveur saturé)',
                OverpassPause::retryDelay(),
            );
        }

        $radius = (int) config('prospection.overpass.radius_meters', 150);
        $query = sprintf(
            '[out:json][timeout:25];nwr(around:%d,%F,%F)["name"];out tags center 100;',
            $radius,
            $latitude,
            $longitude,
        );

        try {
            // Aucune nouvelle tentative immédiate : le serveur demande d'espacer les requêtes.
            $response = ExternalHttp::client((int) config('prospection.overpass.timeout', 30), retries: 0)
                ->asForm()
                ->post(config('prospection.overpass.url'), ['data' => $query]);
        } catch (ConnectionException $exception) {
            $seconds = OverpassPause::start();

            throw new ApiUnavailableException('Overpass injoignable : '.$exception->getMessage(), $seconds + random_int(0, 120));
        }

        try {
            ExternalHttp::ensureAvailable($response, 'Overpass (OpenStreetMap)');
        } catch (ApiUnavailableException $exception) {
            $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;
            $seconds = OverpassPause::start($retryAfter);

            throw new ApiUnavailableException($exception->getMessage().' — pause de '.$seconds.' s', $seconds + random_int(0, 120));
        }

        $response->throw();
        OverpassPause::reset();

        return collect($response->json('elements', []))
            ->map(fn (array $element) => $element['tags'] ?? [])
            ->filter(fn (array $tags) => filled($tags['name'] ?? null))
            ->map(fn (array $tags) => ['tags' => $tags, 'score' => CompanyName::similarity($name, $tags['name'])])
            ->filter(fn (array $candidate) => $candidate['score'] > 0)
            ->sortByDesc('score')
            ->first()['tags'] ?? null;
    }

    /**
     * Informations utiles extraites des tags d'un lieu.
     *
     * @param  array<string, string>  $tags
     * @return array{phone: ?string, website: ?string, email: ?string, signals: array<string, mixed>}
     */
    public static function extract(array $tags): array
    {
        $first = fn (array $keys) => collect($keys)->map(fn (string $key) => $tags[$key] ?? null)->filter()->first();

        $social = collect(['contact:facebook', 'facebook', 'contact:instagram', 'instagram'])
            ->mapWithKeys(fn (string $key) => [$key => $tags[$key] ?? null])
            ->filter();

        return [
            'phone' => $first(['phone', 'contact:phone']),
            'website' => $first(['website', 'contact:website', 'url']),
            'email' => $first(['email', 'contact:email']),
            'signals' => array_filter([
                'osm_name' => $tags['name'] ?? null,
                'opening_hours' => filled($tags['opening_hours'] ?? null),
                'phone' => filled($first(['phone', 'contact:phone', 'contact:mobile'])),
                'social' => $social->isNotEmpty(),
            ], fn ($value) => $value !== null),
        ];
    }
}
