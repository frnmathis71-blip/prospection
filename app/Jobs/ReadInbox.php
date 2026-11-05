<?php

namespace App\Jobs;

use App\Services\Inbox\InboundMessage;
use App\Services\Inbox\InboxProcessor;
use App\Services\Inbox\Mailbox;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Lit la boîte d'envoi (IMAP) et traite réponses, absences et rebonds (mission M10).
 * Planifié toutes les 5 minutes quand IMAP_* est renseigné.
 */
class ReadInbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /**
     * @return array<string, int> nombre de messages par nature
     */
    public function handle(Mailbox $mailbox, InboxProcessor $processor): array
    {
        $counts = [];

        if (! $mailbox->isConfigured()) {
            return $counts;
        }

        foreach ($mailbox->rawMessagesSince(now()->subDays((int) config('prospection.imap.lookback_days', 3))) as $raw) {
            try {
                $kind = $processor->process(InboundMessage::fromRaw($raw));
            } catch (Throwable $exception) {
                report($exception); // un message illisible ne bloque pas les suivants

                continue;
            }

            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }

        return $counts;
    }
}
