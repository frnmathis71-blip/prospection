<?php

namespace App\Services\Inbox;

use Carbon\CarbonInterface;
use Webklex\PHPIMAP\ClientManager;

/**
 * Lecture IMAP de la boîte d'envoi (webklex/php-imap), en LECTURE SEULE : les messages ne sont
 * ni marqués comme lus, ni déplacés, ni supprimés. Accès dans le .env (IMAP_*).
 */
class ImapMailbox implements Mailbox
{
    public function isConfigured(): bool
    {
        return filled(config('prospection.imap.host')) && filled(config('prospection.imap.username')) && filled(config('prospection.imap.password'));
    }

    public function rawMessagesSince(CarbonInterface $since): iterable
    {
        $config = config('prospection.imap');

        $client = (new ClientManager)->make([
            'host' => $config['host'],
            'port' => $config['port'],
            'encryption' => $config['encryption'],
            'validate_cert' => $config['validate_cert'],
            'username' => $config['username'],
            'password' => $config['password'],
            'protocol' => 'imap',
        ]);

        $client->connect();

        try {
            $folder = $client->getFolderByPath($config['folder']);

            if ($folder === null) {
                return;
            }

            $messages = $folder->query()
                ->whereSince($since->toDateString())
                ->leaveUnread()
                ->setFetchBody(true)
                ->get();

            foreach ($messages as $message) {
                yield $message->getHeader()->raw."\r\n\r\n".$message->getRawBody();
            }
        } finally {
            $client->disconnect();
        }
    }
}
