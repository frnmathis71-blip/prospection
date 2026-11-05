<?php

namespace App\Services;

use App\Enums\ContactVerification;
use App\Enums\EmailStatus;
use App\Enums\ProspectStatus;
use App\Enums\SuppressionReason;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\Email;
use App\Models\SuppressionEntry;
use App\Models\Template;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Chiffres du tableau de bord (mission M11) : entonnoir, taux par template / secteur / zone,
 * santé de l'envoi et chiffre d'affaires signé. Les étapes de l'entonnoir sont emboîtées :
 * « réponses », « devis » et « signés » ne comptent que des entreprises contactées par email.
 */
class ProspectingStats
{
    /** Emails réellement partis (une réponse ou un rebond suppose un envoi). */
    public const SENT_STATUSES = [EmailStatus::Sent, EmailStatus::Replied, EmailStatus::Bounced];

    /**
     * Entonnoir : trouvés → avec email → contactés → réponses → devis → signés.
     *
     * @return array{found: int, with_email: int, contacted: int, replied: int, quoted: int, won: int, revenue: float}
     */
    public function funnel(): array
    {
        return [
            'found' => Company::query()->count(),
            'with_email' => Company::query()->whereHas('contacts', fn (Builder $query) => $query->where('verification', '!=', ContactVerification::Invalid))->count(),
            'contacted' => self::contacted()->count(),
            'replied' => self::replied()->count(),
            'quoted' => self::quoted()->count(),
            'won' => self::contacted()->where('status', ProspectStatus::Won)->count(),
            // Chiffre d'affaires signé : tous les prospects gagnés (emails et liste d'appels).
            'revenue' => (float) Company::query()->where('status', ProspectStatus::Won)->sum('quote_amount'),
        ];
    }

    /**
     * Taux de réponse par template (variantes A/B séparées) : emails partis et réponses.
     *
     * @return Collection<int, array{label: string, sent: int, replied: int, reply_rate: ?float}>
     */
    public function byTemplate(): Collection
    {
        $counts = Email::query()
            ->whereIn('status', self::SENT_STATUSES)
            ->whereNotNull('template_id')
            ->selectRaw('template_id, count(*) as sent, sum(case when status = ? then 1 else 0 end) as replied', [EmailStatus::Replied->value])
            ->groupBy('template_id')
            ->get()
            ->keyBy('template_id');

        return Template::query()
            ->whereIn('id', $counts->keys())
            ->orderBy('name')
            ->get()
            ->map(fn (Template $template) => self::row(
                $template->name.($template->variant ? " (variante {$template->variant})" : ''),
                (int) $counts[$template->id]->sent,
                (int) $counts[$template->id]->replied,
            ))
            ->values();
    }

    /**
     * Par secteur ou par zone (département) : entreprises contactées, réponses, signatures.
     *
     * @return Collection<int, array{label: string, sent: int, replied: int, won: int, reply_rate: ?float, conversion_rate: ?float}>
     */
    public function byGroup(string $group): Collection
    {
        $companies = self::contacted()
            ->withExists(['emails as has_reply' => fn (Builder $query) => $query->where('emails.status', EmailStatus::Replied)])
            ->get(['id', 'naf_code', 'department', 'status']);

        return $companies
            ->groupBy(fn (Company $company) => match ($group) {
                'sector' => config('prospection.sectors.'.CompanyScorer::sectorOf($company->naf_code).'.label') ?? 'Autres secteurs',
                default => CompanyResource::DEPARTMENTS[$company->department] ?? ($company->department ?: 'Non renseigné'),
            })
            ->map(fn (Collection $items, string $label) => self::row(
                $label,
                $items->count(),
                $items->where('has_reply', true)->count(),
                $items->where('status', ProspectStatus::Won)->count(),
            ))
            ->sortByDesc('sent')
            ->values();
    }

    /**
     * Santé de l'envoi sur une période : emails partis, rebonds, désinscriptions et leurs taux.
     *
     * @return array{sent: int, bounced: int, unsubscribed: int, bounce_rate: ?float, unsubscribe_rate: ?float}
     */
    public function health(CarbonInterface $since, ?CarbonInterface $until = null): array
    {
        $until ??= now();
        $sent = Email::query()->whereIn('status', self::SENT_STATUSES)->whereBetween('sent_at', [$since, $until])->count();
        $bounced = Email::query()->where('status', EmailStatus::Bounced)->whereBetween('sent_at', [$since, $until])->count();
        $unsubscribed = SuppressionEntry::query()->where('reason', SuppressionReason::Unsubscribed)->whereBetween('created_at', [$since, $until])->count();

        return [
            'sent' => $sent,
            'bounced' => $bounced,
            'unsubscribed' => $unsubscribed,
            'bounce_rate' => self::rate($bounced, $sent),
            'unsubscribe_rate' => self::rate($unsubscribed, $sent),
        ];
    }

    /**
     * @return Builder<Company>
     */
    public static function contacted(): Builder
    {
        return Company::query()->whereHas('emails', fn (Builder $query) => $query->whereIn('emails.status', self::SENT_STATUSES));
    }

    /**
     * @return Builder<Company>
     */
    public static function replied(): Builder
    {
        return self::contacted()->where(fn (Builder $query) => $query
            ->whereHas('emails', fn (Builder $query) => $query->where('emails.status', EmailStatus::Replied))
            ->orWhereIn('status', [ProspectStatus::Replied, ProspectStatus::QuoteSent, ProspectStatus::Won]));
    }

    /**
     * @return Builder<Company>
     */
    public static function quoted(): Builder
    {
        return self::contacted()->where(fn (Builder $query) => $query
            ->whereIn('status', [ProspectStatus::QuoteSent, ProspectStatus::Won])
            ->orWhereNotNull('quote_amount'));
    }

    /**
     * Pourcentage arrondi à 0,1 près ; null sans dénominateur.
     */
    public static function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : null;
    }

    /**
     * @return array{label: string, sent: int, replied: int, won: int, reply_rate: ?float, conversion_rate: ?float}
     */
    private static function row(string $label, int $sent, int $replied, int $won = 0): array
    {
        return [
            'label' => $label,
            'sent' => $sent,
            'replied' => $replied,
            'won' => $won,
            'reply_rate' => self::rate($replied, $sent),
            'conversion_rate' => self::rate($won, $sent),
        ];
    }
}
