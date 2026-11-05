<?php

namespace App\Services\Inbox;

use App\Enums\ActivityType;
use App\Enums\ContactVerification;
use App\Enums\EmailStatus;
use App\Enums\ProspectStatus;
use App\Enums\SuppressionReason;
use App\Models\Email;
use App\Models\InboundMessage as InboundRecord;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Services\CompanyScorer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Traitement d'un message reçu dans la boîte d'envoi (mission M10) :
 * - réponse (In-Reply-To / References) : séquence arrêtée, prospect « A répondu », notification ;
 * - réponse automatique (absence…) : ignorée, la séquence continue ;
 * - rebond dur : adresse invalide + liste d'opposition, séquence arrêtée ;
 * - rebond temporaire : une seule nouvelle tentative.
 * Chaque message n'est traité qu'une fois (table inbound_messages).
 */
class InboxProcessor
{
    public const REPLY = 'reply';

    public const AUTO_REPLY = 'auto_reply';

    public const HARD_BOUNCE = 'hard_bounce';

    public const SOFT_BOUNCE = 'soft_bounce';

    public const IGNORED = 'ignored';

    /** Objets typiques des réponses automatiques (comparés sans accents, en minuscules). */
    private const AUTO_REPLY_SUBJECTS = [
        'reponse automatique', 'absence', 'absent', 'out of office', 'automatic reply', 'auto reply', 'autoreply',
        'auto:', 'conges', 'en conge', 'vacances', 'message automatique', 'accuse de reception',
    ];

    /** Codes SMTP de rebond temporaire (boîte pleine, serveur indisponible…). */
    private const SOFT_SMTP = ['421', '450', '451', '452', '4.'];

    public function process(InboundMessage $message): string
    {
        if (InboundRecord::query()->where('message_key', $message->key())->exists()) {
            return InboundRecord::query()->where('message_key', $message->key())->value('kind');
        }

        [$kind, $email] = match (true) {
            $this->isBounce($message) => $this->handleBounce($message),
            $this->isAutoReply($message) => [self::AUTO_REPLY, $this->originalEmail($message)],
            default => $this->handleReply($message),
        };

        InboundRecord::query()->create([
            'message_key' => $message->key(),
            'kind' => $kind,
            'email_id' => $email?->id,
            'from' => Str::limit((string) $message->from, 250, ''),
            'subject' => Str::limit($message->subject, 250, ''),
            'received_at' => now(),
        ]);

        return $kind;
    }

    public function isAutoReply(InboundMessage $message): bool
    {
        $autoSubmitted = mb_strtolower((string) $message->header('Auto-Submitted'));
        $precedence = mb_strtolower((string) $message->header('Precedence'));
        $subject = Str::lower(Str::ascii($message->subject));

        return ($autoSubmitted !== '' && $autoSubmitted !== 'no')
            || $message->header('X-Autoreply') !== null
            || $message->header('X-Autorespond') !== null
            || mb_strtolower((string) $message->header('X-Auto-Response-Suppress')) === 'all'
            || in_array($precedence, ['auto_reply', 'bulk', 'junk', 'list'], true)
            || collect(self::AUTO_REPLY_SUBJECTS)->contains(fn (string $needle) => str_starts_with($subject, $needle) || str_contains($subject, $needle.' :') || str_contains($subject, $needle.':'));
    }

    public function isBounce(InboundMessage $message): bool
    {
        $from = (string) $message->from;
        $contentType = mb_strtolower((string) $message->header('Content-Type'));
        $subject = Str::lower(Str::ascii($message->subject));

        return str_contains($contentType, 'report-type=delivery-status')
            || $message->header('X-Failed-Recipients') !== null
            || ((str_starts_with($from, 'mailer-daemon@') || str_starts_with($from, 'postmaster@'))
                && preg_match('/undeliver|delivery|non remis|echec|failure|returned|rejet/', $subject));
    }

    /**
     * @return array{0: string, 1: ?Email}
     */
    private function handleBounce(InboundMessage $message): array
    {
        $email = $this->originalEmail($message) ?? $this->emailForRecipient($this->failedRecipient($message));

        if ($email === null) {
            return [self::IGNORED, null];
        }

        $status = $this->deliveryStatus($message);
        $soft = $status !== null && collect(self::SOFT_SMTP)->contains(fn (string $code) => str_starts_with($status, $code));

        if ($soft && $email->retries < 1) {
            // Rebond temporaire : une seule nouvelle tentative, dans les créneaux d'envoi.
            $email->update(['status' => EmailStatus::Queued, 'retries' => $email->retries + 1, 'note' => "Rebond temporaire ({$status}) : nouvelle tentative."]);

            return [self::SOFT_BOUNCE, $email];
        }

        $email->update(['status' => EmailStatus::Bounced, 'note' => 'Rebond'.($status ? " ({$status})" : '').($soft ? ' temporaire répété.' : '.')]);
        $contact = $email->contact;
        $company = $email->company ?? $contact?->company;

        if (! $soft && $contact) {
            $contact->update(['verification' => ContactVerification::Invalid, 'verification_note' => 'Rebond dur'.($status ? " ({$status})" : '').'.']);

            if (! SuppressionEntry::query()->where('email', $contact->email)->exists()) {
                SuppressionEntry::create(['email' => $contact->email, 'reason' => SuppressionReason::HardBounce]);
            }
        }

        $this->cancelQueued($email, 'Séquence arrêtée : l’adresse a rebondi.');

        if ($company) {
            // Rebond dur : statut « Rebond » (bloquant). Rebond temporaire répété : séquence arrêtée seulement.
            if (! $soft && ! $company->status->isBlocking()) {
                $company->update(['status' => ProspectStatus::Bounced]);
            }

            $company->activities()->create([
                'type' => ActivityType::Note,
                'content' => 'Rebond de '.($contact?->email ?? 'l’adresse').($status ? " ({$status})" : '').' : séquence arrêtée.',
                'occurred_at' => now(),
            ]);
            app(CompanyScorer::class)->refresh($company->fresh());
        }

        return [$soft ? self::SOFT_BOUNCE : self::HARD_BOUNCE, $email];
    }

    /**
     * @return array{0: string, 1: ?Email}
     */
    private function handleReply(InboundMessage $message): array
    {
        $email = $this->originalEmail($message, threadOnly: true) ?? $this->emailForRecipient($message->from);

        if ($email === null) {
            return [self::IGNORED, null];
        }

        $email->update(['status' => EmailStatus::Replied]);
        $this->cancelQueued($email, 'Séquence arrêtée : le prospect a répondu.');

        $company = $email->company ?? $email->contact?->company;

        if ($company) {
            if (! $company->status->isBlocking() && ! in_array($company->status, [ProspectStatus::QuoteSent, ProspectStatus::Won, ProspectStatus::Lost], true)) {
                $company->update(['status' => ProspectStatus::Replied]);
            }

            $company->activities()->create([
                'type' => ActivityType::EmailReceived,
                'content' => 'Réponse de '.$message->from.' : « '.$message->subject.' » — '.Str::limit(Str::squish(self::stripQuote($message->text)), 300),
                'occurred_at' => now(),
            ]);

            $url = route('filament.admin.resources.companies.view', ['record' => $company]);

            foreach (User::all() as $user) {
                Notification::make()
                    ->title("{$company->name} a répondu")
                    ->body(Str::limit($message->subject, 120))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->success()
                    ->actions([Action::make('open')->label('Voir le prospect')->url($url)])
                    ->sendToDatabase($user);
            }
        }

        return [self::REPLY, $email];
    }

    /**
     * Email d'origine : par In-Reply-To / References ; pour un rebond, aussi par les Message-ID
     * cités dans le rapport de remise.
     */
    private function originalEmail(InboundMessage $message, bool $threadOnly = false): ?Email
    {
        $ids = [...$message->inReplyTo, ...$message->references];

        if (! $threadOnly) {
            preg_match_all('/<([^<>\s]+@[^<>\s]+)>/', $message->text, $matches);
            $ids = [...$ids, ...$matches[1]];
        }

        if ($ids === []) {
            return null;
        }

        return Email::query()
            ->whereIn('message_id', array_map(fn (string $id) => "<{$id}>", array_unique($ids)))
            ->latest('sent_at')
            ->first();
    }

    /**
     * Dernier email envoyé à cette adresse (repli quand les en-têtes de conversation manquent).
     */
    private function emailForRecipient(?string $address): ?Email
    {
        if (blank($address)) {
            return null;
        }

        return Email::query()
            ->whereIn('status', [EmailStatus::Sent, EmailStatus::Replied])
            ->whereHas('contact', fn ($query) => $query->where('email', mb_strtolower($address)))
            ->latest('sent_at')
            ->first();
    }

    private function failedRecipient(InboundMessage $message): ?string
    {
        if ($failed = $message->header('X-Failed-Recipients')) {
            return trim(Str::before($failed, ','));
        }

        return preg_match('/(?:Final|Original)-Recipient:\s*rfc822;\s*<?([^\s<>]+@[^\s<>]+)>?/i', $message->text, $match) ? $match[1] : null;
    }

    /**
     * Code de remise : « 5.1.1 », « 4.2.2 », ou à défaut le code SMTP (« 550 »).
     */
    private function deliveryStatus(InboundMessage $message): ?string
    {
        if (preg_match('/^Status:\s*([245]\.\d{1,3}\.\d{1,3})/mi', $message->text, $match)) {
            return $match[1];
        }

        if (preg_match('/\b([45]\d\d)[\s-]+(?:[45]\.\d{1,3}\.\d{1,3})?/', $message->text, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Annule les relances encore en file pour l'entreprise dans cette campagne.
     */
    private function cancelQueued(Email $email, string $note): void
    {
        Email::query()
            ->where('campaign_id', $email->campaign_id)
            ->where('company_id', $email->company_id)
            ->whereKeyNot($email->id)
            ->where('status', EmailStatus::Queued)
            ->update(['status' => EmailStatus::Cancelled, 'note' => $note]);
    }

    /**
     * Garde la réponse, sans le message cité (« Le … a écrit : », lignes « > »).
     */
    private static function stripQuote(string $text): string
    {
        $text = preg_split('/^(?:Le .{5,120} a écrit\s*:|On .{5,120} wrote:|-{2,}\s*Original Message|De\s*:.*$)/mu', $text)[0] ?? $text;

        return implode("\n", array_filter(preg_split('/\R/', $text), fn (string $line) => ! str_starts_with(ltrim($line), '>')));
    }
}
