<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CampaignStatus;
use App\Enums\ContactVerification;
use App\Enums\EmailStatus;
use App\Enums\ProspectStatus;
use App\Mail\ProspectMail;
use App\Models\Campaign;
use App\Models\Email;
use App\Models\SuppressionEntry;
use App\Support\BusinessCalendar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envoi conforme (mission M9). Appelé uniquement depuis le job SendNextEmail (file d'attente).
 *
 * À chaque appel, au plus UN email part, et seulement si : jour ouvré et créneau d'envoi,
 * espacement aléatoire écoulé, plafond global du jour, quotas quotidien et horaire de la
 * campagne non atteints. Juste avant l'envoi, tout est revérifié (liste d'opposition comprise) :
 * un email qui ne doit plus partir est annulé, avec la raison.
 */
class EmailSender
{
    public const NEXT_SEND_KEY = 'sending:next-at';

    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * @return Email|null l'email envoyé (ou annulé/en échec), null si rien ne pouvait partir
     */
    public function sendNext(): ?Email
    {
        return Cache::lock('sending:lock', 120)->get(fn () => $this->attempt()) ?: null;
    }

    private function attempt(): ?Email
    {
        if (! BusinessCalendar::isSendingTime()) {
            return null;
        }

        $nextAt = Cache::get(self::NEXT_SEND_KEY);

        if ($nextAt !== null && now()->lt($nextAt)) {
            return null;
        }

        if (self::sentCount(...BusinessCalendar::dayBounds()) >= (int) config('prospection.sending.daily_cap')) {
            return null;
        }

        foreach (Campaign::query()->where('status', CampaignStatus::Active)->orderBy('started_at')->get() as $campaign) {
            if ($this->campaignQuotaReached($campaign)) {
                continue;
            }

            while ($email = $campaign->emails()->where('status', EmailStatus::Queued)->orderBy('id')->first()) {
                $reason = $this->reasonNotToSend($email);

                if ($reason !== null) {
                    // Annulé au dernier moment : on passe au suivant sans attendre.
                    $email->update(['status' => EmailStatus::Cancelled, 'note' => $reason]);

                    continue;
                }

                return $this->deliver($email);
            }

            // Plus rien en file pour l'étape 1 : la campagne est terminée (relances : M10).
            $campaign->update(['status' => CampaignStatus::Completed]);
        }

        return null;
    }

    /**
     * Contrôles de dernière minute. Null = l'email peut partir.
     */
    public function reasonNotToSend(Email $email): ?string
    {
        $email->loadMissing(['contact', 'company', 'template', 'campaign']);
        $contact = $email->contact;
        $company = $email->company ?? $contact?->company;

        return match (true) {
            $contact === null || $company === null => 'Adresse ou entreprise supprimée.',
            SuppressionEntry::blocks($contact->email) => 'Adresse ou domaine sur la liste d’opposition.',
            $company->status->isBlocking() => 'Entreprise au statut « '.$company->status->getLabel().' ».',
            ! $company->is_active => 'Entreprise fermée.',
            $contact->verification !== ContactVerification::Valid => 'Adresse non vérifiée ou invalide.',
            $contact->needsReview() => 'Adresse personnelle pas encore validée.',
            $email->template === null => 'Template supprimé.',
            Email::query()
                ->where('campaign_id', $email->campaign_id)
                ->where('company_id', $company->id)
                ->where('sequence_step_id', $email->sequence_step_id)
                ->whereKeyNot($email->id)
                ->whereIn('status', [EmailStatus::Sent, EmailStatus::Replied, EmailStatus::Bounced])
                ->exists() => 'Entreprise déjà contactée par cette campagne.',
            default => null,
        };
    }

    private function deliver(Email $email): Email
    {
        $contact = $email->contact;
        $company = $email->company ?? $contact->company;
        $rendered = $this->renderer->render($email->template, $company, $contact, $email->campaign->name);

        if (! $rendered->canBeSent()) {
            $email->update(['status' => EmailStatus::Cancelled, 'note' => 'Rendu bloqué : '.implode(' ', $rendered->blocking)]);

            return $email;
        }

        $messageId = Str::uuid().'@'.(Str::afterLast((string) config('mail.from.address'), '@') ?: 'codenyr.local');

        // Espacement aléatoire avant le suivant, posé avant l'envoi (même en cas d'échec).
        [$min, $max] = config('prospection.sending.spacing_minutes', [2, 6]);
        Cache::forever(self::NEXT_SEND_KEY, now()->addSeconds(random_int($min * 60, $max * 60)));

        try {
            Mail::to($contact->email)->send(new ProspectMail($rendered, messageId: $messageId));
        } catch (Throwable $exception) {
            $email->update(['status' => EmailStatus::Failed, 'note' => Str::limit('Échec SMTP : '.$exception->getMessage(), 250)]);
            report($exception);

            return $email;
        }

        $email->update([
            'status' => EmailStatus::Sent,
            'sent_at' => now(),
            'subject' => $rendered->subject,
            'message_id' => "<{$messageId}>",
            'note' => null,
        ]);

        if (in_array($company->status, ProspectStatus::TO_PROCESS, true)) {
            $company->update(['status' => ProspectStatus::Contacted]);
        }

        $company->activities()->create([
            'type' => ActivityType::EmailSent,
            'content' => "« {$rendered->subject} » envoyé à {$contact->email} (campagne « {$email->campaign->name} »).",
            'occurred_at' => now(),
        ]);

        return $email;
    }

    private function campaignQuotaReached(Campaign $campaign): bool
    {
        return self::sentCount(...BusinessCalendar::dayBounds(), campaign: $campaign) >= $campaign->daily_quota
            || self::sentCount(...BusinessCalendar::hourBounds(), campaign: $campaign) >= $campaign->hourly_quota;
    }

    /**
     * Emails partis (ou tentés) entre deux instants, toutes campagnes ou une seule.
     */
    public static function sentCount($from, $to, ?Campaign $campaign = null): int
    {
        return Email::query()
            ->when($campaign, fn ($query) => $query->where('campaign_id', $campaign->id))
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->whereIn('status', [EmailStatus::Sent, EmailStatus::Replied, EmailStatus::Bounced])->whereBetween('sent_at', [$from, $to]))
                ->orWhere(fn ($query) => $query->where('status', EmailStatus::Failed)->whereBetween('updated_at', [$from, $to])))
            ->count();
    }
}
