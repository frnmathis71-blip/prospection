<?php

use App\Enums\DataSource;
use App\Enums\ProspectStatus;
use App\Enums\SuppressionReason;
use App\Enums\TemplateAngle;
use App\Enums\WebsiteState;
use App\Filament\Resources\Templates\Pages\CreateTemplate;
use App\Filament\Resources\Templates\Pages\PreviewTemplate;
use App\Mail\ProspectMail;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SuppressionEntry;
use App\Models\Template;
use App\Models\User;
use App\Models\Website;
use App\Services\TemplateRenderer;
use App\Support\CompanyName;
use Database\Seeders\TemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'prospection.sender' => [
            'name' => 'Camille Exemple',
            'company' => 'Codenyr',
            'address' => '1 place de l’Exemple, 71100 Chalon-sur-Saône',
            'siret' => '000 000 000 00000',
            'phone' => null,
            'website' => 'https://codenyr.fr',
            'privacy_url' => 'https://codenyr.fr/confidentialite',
        ],
        'prospection.emails.cta_url' => 'https://codenyr.fr',
        'prospection.emails.test_recipients' => ['moi@boite-test.example'],
    ]);
});

/** Plombier sans site, connu par son site… ou par l'annuaire. */
function prospect(array $attributes = []): Company
{
    return Company::factory()->create($attributes + [
        'name' => 'SARL PLOMBERIE MARTIN',
        'city' => 'CHALON-SUR-SAONE',
        'naf_code' => '43.22A',
        'founded_on' => '2014-03-01',
        'status' => ProspectStatus::New,
    ]);
}

function contactOf(Company $company, string $email = 'contact@plomberie-martin.fr'): Contact
{
    return Contact::factory()->for($company)->create(['email' => $email, 'source' => DataSource::Website]);
}

function renderFor(Template $template, Company $company, ?Contact $contact = null)
{
    return app(TemplateRenderer::class)->render($template, $company, $contact, 'Pilote novembre');
}

describe('variables', function () {
    it('remplace toutes les variables', function () {
        $company = prospect();
        Website::factory()->for($company)->create(['state' => WebsiteState::Outdated, 'problems' => ['no_https', 'not_responsive'], 'copyright_year' => 2012]);
        $template = Template::factory()->create([
            'subject' => '{nom_entreprise} à {ville}',
            'body' => "Bonjour {prenom_dirigeant|Madame, Monsieur},\n\nLes {secteur} de {ville}, depuis {annee_creation} : {problemes_detectes}.\n\n{expediteur}",
        ]);

        $email = renderFor($template, $company, contactOf($company));

        expect($email->subject)->toBe('Plomberie Martin à Chalon-sur-Saone')
            ->and($email->text)->toContain('Bonjour Madame, Monsieur,')
            ->toContain('Les artisans du bâtiment de Chalon-sur-Saone, depuis 2014 : le site n’est pas sécurisé (pas de HTTPS) et le site n’est pas adapté à l’affichage sur téléphone.')
            ->toContain('Camille Exemple')
            ->not->toContain('{')
            ->and($email->html)->not->toContain('{')
            ->and($email->blocking)->toBe([])
            ->and($email->canBeSent())->toBeTrue();
    });

    it('utilise la valeur de secours d’une variable vide', function () {
        $template = Template::factory()->create(['subject' => 'Bonjour', 'body' => 'Pour les {secteur|entreprises} de {ville|votre ville}.']);

        $email = renderFor($template, prospect(['naf_code' => '99.99Z', 'city' => null]), null);

        expect($email->text)->toContain('Pour les entreprises de votre ville.')
            ->and($email->blocking)->toBe([]);
    });

    it('bloque l’envoi quand une variable est vide sans valeur de secours', function () {
        $template = Template::factory()->create(['subject' => 'Bonjour {prenom_dirigeant}', 'body' => 'Votre site : {problemes_detectes}']);
        $company = prospect();

        $email = renderFor($template, $company, contactOf($company));

        expect($email->canBeSent())->toBeFalse()
            ->and($email->blocking)->toContain(
                'La variable {prenom_dirigeant} est vide pour ce prospect et n’a pas de valeur de secours.',
                'La variable {problemes_detectes} est vide pour ce prospect et n’a pas de valeur de secours.',
            );
    });

    it('bloque l’envoi pour une variable inconnue', function () {
        $template = Template::factory()->create(['subject' => 'Bonjour', 'body' => 'Bonjour {nom_entrepirse}']);
        $company = prospect();

        expect(renderFor($template, $company, contactOf($company))->blocking)->toBe(['Variable inconnue : {nom_entrepirse}.']);
    });

    it('liste les problèmes du site analysé (M5), un par ligne quand la variable est seule', function () {
        $company = prospect();
        Website::factory()->for($company)->create([
            'state' => WebsiteState::Outdated,
            'problems' => ['old_copyright', 'no_https', 'not_responsive', 'no_legal_notice'],
            'copyright_year' => 2012,
        ]);
        $template = Template::factory()->outdated()->create(['body' => "J’ai relevé :\n\n{problemes_detectes}\n\nBonne journée"]);

        $email = renderFor($template, $company, contactOf($company));

        // Les 3 plus importants, les plus graves d'abord ; jamais un problème non détecté.
        expect($email->text)->toContain("- le site n’est pas sécurisé (pas de HTTPS)\n- le site n’est pas adapté à l’affichage sur téléphone\n- le site ne semble plus mis à jour (© 2012)")
            ->not->toContain('mentions légales')
            ->and($email->html)->toContain('<li>le site n’est pas sécurisé (pas de HTTPS)</li>');
    });

    it('échappe les données du prospect dans le HTML', function () {
        $company = prospect(['name' => 'Martin <script>alert(1)</script> & *Fils*']);
        $template = Template::factory()->create(['subject' => 'Bonjour', 'body' => 'Bonjour {nom_entreprise}']);

        expect(renderFor($template, $company)->html)->not->toContain('<script>alert')->toContain('&lt;script&gt;');
    });
});

describe('mentions obligatoires', function () {
    it('contient l’identité Codenyr, la désinscription, la confidentialité et le bouton avec UTM', function () {
        $company = prospect();
        $template = Template::factory()->create(['name' => 'J0 — Sans site', 'variant' => 'B']);

        $email = renderFor($template, $company, contactOf($company));

        expect($email->html)
            ->toContain('Camille Exemple')
            ->toContain('Codenyr, 1 place de l’Exemple, 71100 Chalon-sur-Saône')
            ->toContain('SIRET 000 000 000 00000')
            ->toContain('publiées sur votre site internet')
            ->toContain('href="https://codenyr.fr/confidentialite"')
            ->toContain('href="'.e($email->unsubscribeUrl).'"')
            ->toContain('href="'.e($email->ctaUrl).'"')
            ->toContain('Voir mes réalisations');

        expect($email->ctaUrl)->toBe('https://codenyr.fr?utm_source=prospection&utm_medium=email&utm_campaign=pilote-novembre&utm_content=no-website-b')
            ->and($email->unsubscribeUrl)->toContain('/desinscription?e=')->toContain('signature=');
    });

    it('ne contient aucun pixel ni image de suivi, même avec une image dans le corps', function () {
        $template = Template::factory()->create(['body' => "Bonjour\n\n![pixel](https://suivi.example/p.gif)\n\n<img src=\"https://suivi.example/o.gif\" width=\"1\" height=\"1\">"]);
        $company = prospect();

        $html = renderFor($template, $company, contactOf($company))->html;
        preg_match_all('/<img\b[^>]*\bsrc="([^"]*)"/i', $html, $images);

        // Une seule image : le bandeau Codenyr, intégré à l'email (cid), jamais chargé depuis un serveur.
        expect($images[1])->toBe(['cid:'.TemplateRenderer::HEADER_CID])
            ->and($html)->not->toContain('background-image')
            ->not->toMatch('/[?&](uid|contact|email)=/');
    });

    it('intègre le bandeau aux couleurs de codenyr.fr dans l’email, sans chargement distant', function () {
        $company = prospect();
        $email = renderFor(Template::factory()->create(['body' => 'Bonjour']), $company, contactOf($company));

        expect($email->inlineImages)->toBe([TemplateRenderer::HEADER_CID => resource_path('images/email-header.jpg')])
            ->and($email->html)->toContain('alt="Codenyr — Votre savoir-faire. Un site à sa hauteur."');

        $symfony = (new ProspectMail($email))->to('contact@plomberie-martin.fr')->from('camille@envoi-dedie.example');
        $sent = Mail::mailer('array')->send($symfony)->getSymfonySentMessage()->getOriginalMessage();
        $parts = collect($sent->getAttachments());

        expect($parts)->toHaveCount(1)
            ->and($parts->first()->getContentId())->toBe(TemplateRenderer::HEADER_CID)
            ->and($parts->first()->getMediaType().'/'.$parts->first()->getMediaSubtype())->toBe('image/jpeg')
            ->and($parts->first()->getPreparedHeaders()->getHeaderBody('Content-Disposition'))->toBe('inline');

        // Aperçu Filament : image en data URI (le « cid » ne s'affiche que dans une messagerie).
        expect(app(TemplateRenderer::class)->render(Template::first(), $company, contactOf($company, 'apercu@plomberie-martin.fr'), preview: true)->html)
            ->toContain('src="data:image/jpeg;base64,');
    });

    it('peut se passer du bandeau (EMAIL_HEADER_IMAGE=false)', function () {
        config(['prospection.emails.header_image' => null]);
        $company = prospect();

        $email = renderFor(Template::factory()->create(['body' => 'Bonjour']), $company, contactOf($company));

        expect($email->html)->not->toMatch('/<img\b/i')->and($email->inlineImages)->toBe([]);
    });

    it('produit une version texte brut avec le lien du bouton et la désinscription', function () {
        $company = prospect();
        $template = Template::factory()->create(['body' => "Bonjour **{nom_entreprise}**,\n\n- point un\n- point deux"]);

        $email = renderFor($template, $company, contactOf($company));

        expect($email->text)
            ->toContain('Bonjour Plomberie Martin,')
            ->toContain("- point un\n- point deux")
            ->toContain('Voir mes réalisations : '.$email->ctaUrl)
            ->toContain('Se désinscrire en un clic : '.$email->unsubscribeUrl)
            ->toContain('SIRET 000 000 000 00000')
            ->not->toMatch('/<[a-z][^>]*>/i');
    });

    it('bloque l’envoi tant que l’identité de l’expéditeur est incomplète', function () {
        config(['prospection.sender.siret' => null, 'prospection.sender.address' => '']);
        $company = prospect();

        $email = renderFor(Template::factory()->create(['body' => 'Bonjour']), $company, contactOf($company));

        expect($email->canBeSent())->toBeFalse()
            ->and($email->blocking)->toContain(
                'Identité de l’expéditeur incomplète : adresse (SENDER_ADDRESS) à renseigner dans le .env.',
                'Identité de l’expéditeur incomplète : SIRET (SENDER_SIRET) à renseigner dans le .env.',
            );
    });

    it('ne peut pas être envoyé sans adresse (lien de désinscription personnel obligatoire)', function () {
        $email = renderFor(Template::factory()->create(['body' => 'Bonjour']), prospect());

        expect($email->unsubscribeUrl)->toBeNull()->and($email->canBeSent())->toBeFalse();
    });

    it('ajoute les en-têtes de désinscription en un clic à l’email', function () {
        $company = prospect();
        $email = renderFor(Template::factory()->create(['body' => 'Bonjour']), $company, contactOf($company));

        $headers = (new ProspectMail($email))->headers()->text;

        expect($headers)->toBe([
            'List-Unsubscribe' => '<'.$email->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    });
});

describe('templates de départ', function () {
    it('se rendent sans blocage, entre 80 et 150 mots, pour un prospect complet', function () {
        $this->seed(TemplateSeeder::class);
        $noSite = prospect();
        Website::factory()->none()->for($noSite)->create();
        $outdated = prospect(['name' => 'Domaine des Grands Prés']);
        Website::factory()->for($outdated)->create(['state' => WebsiteState::Outdated, 'problems' => ['no_https', 'not_responsive', 'old_copyright'], 'copyright_year' => 2013]);

        expect(Template::count())->toBe(6);

        Template::all()->each(function (Template $template) use ($noSite, $outdated) {
            $company = $template->angle === TemplateAngle::OutdatedWebsite ? $outdated : $noSite;
            $email = renderFor($template, $company, contactOf($company, 'contact-'.$template->id.'@plomberie-martin.fr'));

            expect($email->blocking)->toBe([], $template->name)
                ->and($email->wordCount)->toBeGreaterThanOrEqual(80)->toBeLessThanOrEqual(150);
        });
    });

    it('ne modifie jamais un template existant quand on relance le seeder', function () {
        $this->seed(TemplateSeeder::class);
        Template::firstWhere('name', 'J0 — Sans site')->update(['subject' => 'Mon objet']);

        $this->seed(TemplateSeeder::class);

        expect(Template::count())->toBe(6)
            ->and(Template::firstWhere('name', 'J0 — Sans site')->subject)->toBe('Mon objet');
    });
});

describe('éditeur et aperçu', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->withAppAuthentication()->create());
    });

    it('crée un template et refuse une variable inconnue', function () {
        Livewire::test(CreateTemplate::class)
            ->fillForm(['name' => 'Test', 'angle' => TemplateAngle::NoWebsite->value, 'subject' => 'Bonjour {nom_entrepirse}', 'body' => 'Bonjour'])
            ->call('create')
            ->assertHasFormErrors(['subject']);

        Livewire::test(CreateTemplate::class)
            ->fillForm(['name' => 'Test', 'angle' => TemplateAngle::NoWebsite->value, 'subject' => 'Bonjour {nom_entreprise}', 'body' => 'Bonjour {prenom_dirigeant|Madame}'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Template::firstWhere('name', 'Test'))->not->toBeNull();
    });

    it('affiche l’aperçu avec un vrai prospect correspondant à l’angle', function () {
        $company = prospect();
        Website::factory()->none()->for($company)->create();
        contactOf($company);
        $template = Template::factory()->create(['subject' => 'Bonjour {nom_entreprise}', 'body' => 'Bonjour {ville}']);

        Livewire::test(PreviewTemplate::class, ['record' => $template->getKey()])
            ->assertFormSet(['company_id' => $company->id])
            ->assertSee('Objet : Bonjour Plomberie Martin')
            ->assertSee('Email court')
            ->assertSee('Version texte brut');
    });

    it('envoie un test uniquement vers ses propres adresses, par la file', function () {
        Mail::fake();
        $company = prospect();
        Website::factory()->none()->for($company)->create();
        contactOf($company);
        $template = Template::factory()->create();

        $page = Livewire::test(PreviewTemplate::class, ['record' => $template->getKey()])
            ->callAction('sendTest', data: ['recipient' => 'moi@boite-test.example']);

        Mail::assertQueued(ProspectMail::class, fn (ProspectMail $mail) => $mail->hasTo('moi@boite-test.example')
            && $mail->isTest
            && str_contains($mail->email->unsubscribeUrl, rtrim(strtr(base64_encode('moi@boite-test.example'), '+/', '-_'), '=')));
        Mail::assertNothingSent();

        $page->instance()->sendTest('contact@plomberie-martin.fr');
        Mail::assertQueuedCount(1);
    });
});

describe('désinscription', function () {
    it('fonctionne sans connexion avec le lien signé et ajoute l’adresse à la liste d’opposition', function () {
        $company = prospect();
        $contact = contactOf($company);

        $this->get(TemplateRenderer::unsubscribeUrl('Contact@Plomberie-Martin.fr'))
            ->assertOk()
            ->assertSee('Désinscription confirmée');

        expect(SuppressionEntry::firstWhere('email', 'contact@plomberie-martin.fr')->reason)->toBe(SuppressionReason::Unsubscribed)
            ->and($contact->fresh()->isSuppressed())->toBeTrue()
            ->and($company->fresh()->status)->toBe(ProspectStatus::Unsubscribed);
    });

    it('accepte la désinscription en un clic des messageries (POST sans jeton CSRF)', function () {
        $this->post(TemplateRenderer::unsubscribeUrl('contact@plomberie-martin.fr'), ['List-Unsubscribe' => 'One-Click'])->assertOk();

        expect(SuppressionEntry::blocks('contact@plomberie-martin.fr'))->toBeTrue();
    });

    it('refuse un lien non signé ou modifié', function () {
        $this->get('/desinscription?e='.base64_encode('autre@exemple.fr'))->assertForbidden();
        $this->get(TemplateRenderer::unsubscribeUrl('contact@plomberie-martin.fr', signed: false))->assertForbidden();

        expect(SuppressionEntry::count())->toBe(0);
    });
});

it('présente proprement le nom et la ville du prospect', function (string $raw, string $expected) {
    expect(CompanyName::display($raw))->toBe($expected);
})->with([
    ['SARL PLOMBERIE MARTIN', 'Plomberie Martin'],
    ['EARL DOMAINE DES GRANDS PRES', 'Domaine des Grands Pres'],
    ['BOULANGERIE DUPONT SAS', 'Boulangerie Dupont'],
    ["Crêp'80", "Crêp'80"],
    ['MONSIEUR JEAN MARTIN', 'Jean Martin'],
]);
