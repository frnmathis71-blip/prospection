<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client HTTP commun aux sources externes : User-Agent honnête, délai maximum,
 * et nouvelles tentatives sur 429 / erreurs serveur / coupure réseau.
 */
class ExternalHttp
{
    /** Nombre de nouvelles tentatives immédiates avant de remettre le job en file. */
    public const RETRIES = 2; // soit 3 essais au total

    /** Au-delà, un Retry-After n'est pas attendu dans la requête : le job est remis en file. */
    private const MAX_INLINE_WAIT_SECONDS = 10;

    /**
     * @param  int  $retries  nouvelles tentatives immédiates (0 pour un service saturé qui demande d'espacer)
     */
    public static function client(int $timeout, int $retries = self::RETRIES): PendingRequest
    {
        return Http::withUserAgent(config('prospection.user_agent'))
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(min($timeout, 10))
            ->retry(
                $retries + 1, // nombre total d'essais

                fn (int $attempt, Throwable $exception) => self::waitMilliseconds($attempt, $exception),
                fn (Throwable $exception) => self::isTransient($exception),
                throw: false,
            );
    }

    /**
     * Transforme une réponse en échec « réessayable plus tard » si le service est indisponible.
     */
    public static function ensureAvailable(Response $response, string $service): Response
    {
        if ($response->status() === 429 || $response->serverError()) {
            throw new ApiUnavailableException(
                "{$service} indisponible (HTTP {$response->status()})",
                self::retryAfter($response) ?? 60,
            );
        }

        return $response;
    }

    public static function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    private static function waitMilliseconds(int $attempt, Throwable $exception): int
    {
        if ($exception instanceof RequestException && ($retryAfter = self::retryAfter($exception->response)) !== null) {
            return min($retryAfter, self::MAX_INLINE_WAIT_SECONDS) * 1000;
        }

        return $attempt * 1000; // 1 s, 2 s, 3 s
    }

    private static function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : null;
    }
}
