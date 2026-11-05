<?php

namespace App\Filament\Widgets;

use App\Services\ProspectingStats;
use Filament\Widgets\Widget;

/**
 * Taux de réponse et de conversion par template (A/B compris), par secteur et par zone (M11),
 * pour savoir quoi cibler.
 */
class RatesWidget extends Widget
{
    protected static ?int $sort = -1;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.rates';

    protected int|string|array $columnSpan = 'full';

    /** template, sector ou zone */
    public string $group = 'template';

    public function setGroup(string $group): void
    {
        $this->group = in_array($group, ['template', 'sector', 'zone'], true) ? $group : 'template';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $stats = app(ProspectingStats::class);

        return [
            'rows' => $this->group === 'template' ? $stats->byTemplate() : $stats->byGroup($this->group),
            'groups' => ['template' => 'Par template', 'sector' => 'Par secteur', 'zone' => 'Par zone'],
        ];
    }
}
