<?php

namespace App\Services\Inbox;

use Illuminate\Support\Str;
use ZBateson\MailMimeParser\Message;
use ZBateson\MailMimeParser\Message\IMimePart;

/**
 * Message reçu dans la boîte d'envoi (mission M10), lu depuis sa source brute (RFC 822).
 */
final class InboundMessage
{
    /**
     * @param  array<string, string>  $headers  en-têtes utiles, noms en minuscules
     * @param  list<string>  $inReplyTo  Message-ID cités (In-Reply-To), sans chevrons
     * @param  list<string>  $references  Message-ID cités (References), sans chevrons
     */
    public function __construct(
        public readonly ?string $messageId,
        public readonly ?string $from,
        public readonly string $subject,
        public readonly array $headers,
        public readonly array $inReplyTo,
        public readonly array $references,
        public readonly string $text,
        public readonly string $raw,
        public readonly ?string $date = null,
    ) {}

    public static function fromRaw(string $raw): self
    {
        $message = Message::from($raw, false);

        $headers = [];
        foreach ($message->getAllHeaders() as $header) {
            $headers[mb_strtolower($header->getName())] ??= (string) $header->getRawValue();
        }

        // Texte de toutes les parties (dont les rapports de remise des rebonds), décodé.
        $text = collect($message->getAllParts())
            ->reject(fn ($part) => $part instanceof IMimePart && $part->isMultiPart())
            ->map(fn ($part) => (string) $part->getContent())
            ->implode("\n");

        return new self(
            messageId: self::ids($headers['message-id'] ?? '')[0] ?? null,
            from: mb_strtolower((string) $message->getHeaderValue('From')) ?: null,
            subject: (string) $message->getSubject(),
            headers: $headers,
            inReplyTo: self::ids($headers['in-reply-to'] ?? ''),
            references: self::ids($headers['references'] ?? ''),
            text: $text,
            raw: $raw,
            date: $headers['date'] ?? null,
        );
    }

    /**
     * Clé unique pour ne traiter qu'une fois le même message.
     */
    public function key(): string
    {
        return $this->messageId ?? 'sha1:'.sha1($this->raw);
    }

    public function header(string $name): ?string
    {
        return $this->headers[mb_strtolower($name)] ?? null;
    }

    /**
     * « <a@b> <c@d> » → ['a@b', 'c@d'].
     *
     * @return list<string>
     */
    public static function ids(string $value): array
    {
        preg_match_all('/<([^<>\s]+@[^<>\s]+)>/', $value, $matches);

        return array_values(array_unique($matches[1] ?: (Str::contains($value, '@') && ! Str::contains($value, ' ') ? [trim($value, '<> ')] : [])));
    }
}
