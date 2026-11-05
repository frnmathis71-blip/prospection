<x-filament-widgets::widget>
    <x-filament::section heading="Taux de réponse et de conversion">
        <x-slot name="afterHeader">
            <x-filament::tabs>
                @foreach ($groups as $key => $label)
                    <x-filament::tabs.item :active="$group === $key" wire:click="setGroup('{{ $key }}')">
                        {{ $label }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
        </x-slot>

        @if ($rows->isEmpty())
            <p style="font-size:.875rem;opacity:.7;">Aucun email envoyé pour l’instant.</p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%;font-size:.875rem;border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left;border-bottom:1px solid rgba(127,127,127,.25);">
                            <th style="padding:.5rem .5rem .5rem 0;">{{ $group === 'template' ? 'Template' : ($group === 'sector' ? 'Secteur' : 'Zone') }}</th>
                            <th style="padding:.5rem;text-align:right;">{{ $group === 'template' ? 'Envoyés' : 'Contactées' }}</th>
                            <th style="padding:.5rem;text-align:right;">Réponses</th>
                            <th style="padding:.5rem;text-align:right;">Taux de réponse</th>
                            @if ($group !== 'template')
                                <th style="padding:.5rem;text-align:right;">Signés</th>
                                <th style="padding:.5rem;text-align:right;">Conversion</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-bottom:1px solid rgba(127,127,127,.12);">
                                <td style="padding:.5rem .5rem .5rem 0;">{{ $row['label'] }}</td>
                                <td style="padding:.5rem;text-align:right;">{{ $row['sent'] }}</td>
                                <td style="padding:.5rem;text-align:right;">{{ $row['replied'] }}</td>
                                <td style="padding:.5rem;text-align:right;font-weight:600;">{{ $row['reply_rate'] === null ? '—' : str_replace('.', ',', $row['reply_rate']).' %' }}</td>
                                @if ($group !== 'template')
                                    <td style="padding:.5rem;text-align:right;">{{ $row['won'] }}</td>
                                    <td style="padding:.5rem;text-align:right;">{{ $row['conversion_rate'] === null ? '—' : str_replace('.', ',', $row['conversion_rate']).' %' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p style="margin-top:.5rem;font-size:.75rem;opacity:.7;">Clics sur le bouton : à suivre dans l’outil d’analyse de codenyr.fr (paramètres UTM), sans suivi individuel des prospects.</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
