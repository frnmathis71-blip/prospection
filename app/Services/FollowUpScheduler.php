<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\EmailStatus;
use App\Enums\ProspectStatus;
use App\Models\Campaign;
use App\Models\Email;
use App\Models\SequenceStep;
use App\Models\SuppressionEntry;
use App\Support\BusinessCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Relances d'une séquence (mission M10) : l'étape N+1 est mise en file le jour ouvré prévu
 * (« J+4 », « J+12 » comptés en jours ouvrés depuis le premier email), tant que le prospect
 * n'a ni répondu, ni rebondi, ni demandé à ne plus être contacté.
 */
class FollowUpScheduler
{
    /** Statuts du prospect qui permettent encore une relance. */
    public const FOLLOW_UP_STATUSES = [ProspectStatus::Contacted, ProspectStatus::FollowedUp];

    /** Statuts des emails d'une campagne qui arrêtent la séquence pour l'entreprise. */
    public const STOPPING_EMAIL_STATUSES = [EmailStatus::Replied, EmailStatus::Bounced];

    /**
     * Met en file les relances arrivées à échéance. Renvoie le nombre de relances créées.
     */
    public function enqueueDue(): int
    {
        $count = 0;
        $today = BusinessCalendar::local()->startOfDay();

        foreach (Campaign::query()->where('status', CampaignStatus::Active)->with('sequence.steps')->get() as $campaign) {
            foreach ($this->candidates($campaign) as [$previous, $step, $firstSentAt]) {
                if ($today->lt(BusinessCalendar::addBusinessDays($firstSentAt, $step->delay_days))) {
                    continue;
                }

                if ($this->stopReason($previous) !== null) {
                    continue;
                }

                Email::query()->firstOrCreate(
                    ['campaign_id' => $campaign->id, 'company_id' => $previous->company_id, 'sequence_step_id' => $step->id],
                    ['contact_id' => $previous->contact_id, 'template_id' => $step->template_id, 'status' => EmailStatus::Queued],
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Vrai si au moins une relance peut encore être mise en file plus tard.
     */
    public function hasPending(Campaign $campaign): bool
    {
        return $this->candidates($campaign)->contains(fn (array $candidate) => $this->stopReason($candidate[0]) === null);
    }

    /**
     * Raison d'arrêter la séquence pour l'entreprise de cet email (null = elle continue).
     */
    public function stopReason(Email $email): ?string
    {
        $email->loadMissing(['company', 'contact']);

        return match (true) {
            $email->company === null || $email->contact === null => 'Entreprise ou adresse supprimée.',
            ! in_array($email->company->status, self::FOLLOW_UP_STATUSES, true) => 'Prospect au statut « '.$email->company->status->getLabel().' ».',
            Email::query()
                ->where('campaign_id', $email->campaign_id)
                ->where('company_id', $email->company_id)
                ->whereIn('status', self::STOPPING_EMAIL_STATUSES)
                ->exists() => 'Le prospect a répondu ou l’adresse a rebondi.',
            SuppressionEntry::blocks($email->contact->email) => 'Adresse ou domaine sur la liste d’opposition.',
            default => null,
        };
    }

    /**
     * Derniers emails envoyés de chaque entreprise dont l'étape suivante n'existe pas encore.
     *
     * @return Collection<int, array{0: Email, 1: SequenceStep, 2: Carbon}>
     */
    private function candidates(Campaign $campaign): Collection
    {
        $steps = $campaign->sequence?->steps ?? collect();
        $candidates = collect();

        foreach ($steps->where('position', '>', 1) as $step) {
            $previousStep = $steps->firstWhere('position', $step->position - 1);
            $firstStep = $steps->firstWhere('position', 1);

            if ($previousStep === null || $firstStep === null) {
                continue;
            }

            $previousEmails = Email::query()
                ->where('campaign_id', $campaign->id)
                ->where('sequence_step_id', $previousStep->id)
                ->where('status', EmailStatus::Sent)
                ->whereNotNull('company_id')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('emails as next')
                    ->whereColumn('next.campaign_id', 'emails.campaign_id')
                    ->whereColumn('next.company_id', 'emails.company_id')
                    ->where('next.sequence_step_id', $step->id))
                ->get();

            $firstSent = Email::query()
                ->where('campaign_id', $campaign->id)
                ->where('sequence_step_id', $firstStep->id)
                ->whereIn('company_id', $previousEmails->pluck('company_id'))
                ->pluck('sent_at', 'company_id');

            foreach ($previousEmails as $previous) {
                if ($firstSentAt = $firstSent[$previous->company_id] ?? null) {
                    $candidates->push([$previous, $step, Carbon::parse($firstSentAt)]);
                }
            }
        }

        return $candidates;
    }
}
