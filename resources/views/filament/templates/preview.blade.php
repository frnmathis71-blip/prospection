{{-- Aperçu d'un template rendu pour un prospect (M8). --}}
@php
    /** @var \App\Services\RenderedEmail|null $email */
@endphp

@if ($email === null)
    <x-filament::section>
        Choisissez un prospect pour afficher l’aperçu.
    </x-filament::section>
@else
    <div style="display:grid;gap:1.5rem;">
        @if ($email->blocking !== [])
            <x-filament::section icon="heroicon-o-no-symbol" icon-color="danger" heading="Envoi impossible pour ce prospect">
                <ul style="list-style:disc;padding-left:1.25rem;">
                    @foreach ($email->blocking as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($email->warnings !== [])
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning" heading="Conseils">
                <ul style="list-style:disc;padding-left:1.25rem;">
                    @foreach ($email->warnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section heading="Objet : {{ $email->subject }}" description="{{ $email->wordCount }} mots dans le corps (conseillé : {{ config('prospection.emails.min_words') }} à {{ config('prospection.emails.max_words') }}). Le lien de désinscription de l’aperçu est désactivé.">
            <iframe
                title="Aperçu HTML"
                sandbox
                srcdoc="{{ $email->html }}"
                style="width:100%;height:640px;border:1px solid rgba(127,127,127,.25);border-radius:.5rem;background:#fff;"
            ></iframe>
        </x-filament::section>

        <x-filament::section heading="Version texte brut" collapsible collapsed>
            <pre style="white-space:pre-wrap;font-size:.8125rem;line-height:1.5;margin:0;">{{ $email->text }}</pre>
        </x-filament::section>

        <x-filament::section heading="Valeurs des variables pour ce prospect" collapsible collapsed>
            <dl style="display:grid;grid-template-columns:max-content 1fr;gap:.25rem 1rem;font-size:.875rem;">
                @foreach (\App\Services\TemplateRenderer::VARIABLES as $name => $label)
                    <dt><code>{{ '{'.$name.'}' }}</code></dt>
                    <dd>{{ filled($values[$name] ?? null) ? $values[$name] : '— (vide : valeur de secours utilisée, sinon envoi bloqué)' }}</dd>
                @endforeach
            </dl>
        </x-filament::section>
    </div>
@endif
