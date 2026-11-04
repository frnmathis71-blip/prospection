<?php

namespace App\Services;

use RuntimeException;

/**
 * Une API externe ne répond pas (erreur serveur, réseau) ou demande de ralentir (429),
 * même après les nouvelles tentatives immédiates. Le job concerné est remis en file
 * pour plus tard au lieu d'échouer.
 */
class ApiUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 60)
    {
        parent::__construct($message);
    }
}
