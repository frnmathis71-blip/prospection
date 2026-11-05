<?php

namespace App\Jobs;

use App\Services\EmailSender;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envoie au plus un email de prospection (mission M9), par la file d'attente.
 * Planifié chaque minute ; EmailSender décide s'il peut partir (créneau, quotas, espacement)
 * et revérifie la liste d'opposition au dernier moment.
 */
class SendNextEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Jamais de nouvelle tentative automatique : un email ne doit pas partir deux fois. */
    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function handle(EmailSender $sender): void
    {
        $sender->sendNext();
    }
}
