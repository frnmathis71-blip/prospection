{{-- Registre du traitement (M12) : fiche simple au sens de l'article 30 du RGPD. --}}
@php
    $rows = [
        'Responsable du traitement' => trim(implode(', ', array_filter([$sender['name'] ?? null, $sender['company'] ?? null, $sender['address'] ?? null, filled($sender['siret'] ?? null) ? 'SIRET '.$sender['siret'] : null]))) ?: 'À compléter dans le .env (SENDER_*)',
        'Finalité' => 'Prospection commerciale B2B : proposer la création ou la refonte de sites internet à des entreprises de Bourgogne.',
        'Base légale' => 'Intérêt légitime (art. 6.1.f RGPD). Prospection par email de professionnels sur une offre en rapport avec leur activité (art. L34-5 CPCE), avec opposition simple et gratuite dans chaque email.',
        'Personnes concernées' => 'Entreprises (artisans, commerces, professions libérales…) et, pour les entrepreneurs individuels, leur dirigeant. Jamais de particuliers.',
        'Données traitées' => 'Nom, SIREN, code NAF, adresse et téléphone de l’entreprise, effectif, date de création ; adresse email publiée (générique de préférence), avec sa source et sa date de collecte ; état du site internet ; historique des échanges. Aucun nom de dirigeant, aucune donnée sensible, aucun réseau social privé.',
        'Sources' => 'API Recherche d’entreprises et BODACC (données publiques), OpenStreetMap (© contributeurs, ODbL), site internet de l’entreprise (robots.txt respecté), imports CSV, saisie manuelle. Aucun fichier acheté, aucun scraping d’annuaire.',
        'Destinataires' => 'Uniquement '.($sender['company'] ?? 'Codenyr').'. Sous-traitants techniques : hébergeur du serveur, fournisseur d’envoi d’emails. Aucune cession ni revente.',
        'Durée de conservation' => "Prospect sans réponse : {$retention} ans après le dernier contact (ou la collecte), puis suppression automatique chaque nuit. Liste d’opposition : conservée sans limite pour garantir qu’une personne opposée n’est plus jamais contactée.",
        'Droits' => 'Opposition : lien de désinscription en un clic dans chaque email. Accès : export ci-dessus. Effacement et rectification : sur demande, depuis la fiche du prospect. Politique de confidentialité : '.($sender['privacy_url'] ?? '—').'.',
        'Sécurité' => 'Accès réservé à un seul utilisateur avec double authentification, HTTPS, aucune page publique hors désinscription (lien signé), sauvegardes quotidiennes chiffrées, aucun pixel ni suivi individuel des ouvertures.',
    ];
@endphp

<div style="display:grid;gap:1.5rem;">
    <x-filament::section heading="Fiche du traitement">
        <dl style="display:grid;grid-template-columns:minmax(10rem,14rem) 1fr;gap:.75rem 1.5rem;font-size:.875rem;line-height:1.5;">
            @foreach ($rows as $label => $value)
                <dt style="font-weight:600;">{{ $label }}</dt>
                <dd>{{ $value }}</dd>
            @endforeach
        </dl>
    </x-filament::section>

    <x-filament::section heading="Volumes actuels">
        <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:1rem;font-size:.875rem;">
            <div><dt style="opacity:.7;">Entreprises</dt><dd style="font-size:1.25rem;font-weight:600;">{{ $counts['companies'] }}</dd></div>
            <div><dt style="opacity:.7;">Adresses email</dt><dd style="font-size:1.25rem;font-weight:600;">{{ $counts['contacts'] }}</dd></div>
            <div><dt style="opacity:.7;">Liste d’opposition</dt><dd style="font-size:1.25rem;font-weight:600;">{{ $counts['suppressed'] }}</dd><dd style="opacity:.7;">dont {{ $counts['unsubscribed'] }} désinscriptions</dd></div>
            <div><dt style="opacity:.7;">À purger cette nuit</dt><dd style="font-size:1.25rem;font-weight:600;">{{ $counts['to_purge'] }}</dd></div>
        </dl>
    </x-filament::section>
</div>
