<?php

use App\Enums\WebsiteProblem;
use App\Services\HtmlInspector;

it('retient l’année la plus récente d’une mention de copyright', function (string $html, ?int $year) {
    expect((new HtmlInspector)->copyrightYear($html, 2026))->toBe($year);
})->with([
    'symbole' => ['<footer>© 2012 Menuiserie</footer>', 2012],
    'entité HTML' => ['<p>&copy; 2014 - Tous droits réservés</p>', 2014],
    'plage' => ['<p>© 2015-2019 Garage Durand</p>', 2019],
    'mot copyright' => ['<p>Copyright 2016 Boulangerie</p>', 2016],
    '(c)' => ['<p>(c) 2018 Coiffure</p>', 2018],
    'année future ignorée' => ['<p>© 2031</p>', null],
    'pas de copyright' => ['<p>Ouvert depuis 2005</p>', null],
    'dans un script ignoré' => ['<script>var c = "© 2010";</script><p>Bienvenue</p>', null],
]);

it('reconnaît une balise viewport adaptée aux téléphones', function (string $meta, bool $responsive) {
    expect((new HtmlInspector)->isResponsive("<head>{$meta}</head>"))->toBe($responsive);
})->with([
    ['<meta name="viewport" content="width=device-width, initial-scale=1">', true],
    ["<meta content='width = device-width' name='viewport'>", true],
    ['<meta name="viewport" content="width=1024">', false],
    ['<meta name="description" content="width=device-width">', false],
    ['', false],
]);

it('repère les versions de jQuery et de CMS', function (string $html, array $technologies) {
    expect((new HtmlInspector)->technologies($html))->toBe($technologies);
})->with([
    'CDN Google' => ['<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.8.3/jquery.min.js"></script>', ['jQuery' => '1.8.3']],
    'WordPress sans generator' => ['<link href="/wp-content/x.css"><script src="/wp-includes/js/wp-emoji.js?ver=4.7.2"></script>', ['WordPress' => '4.7.2']],
    'Joomla' => ['<meta name="generator" content="Joomla! 3.9 - Open Source Content Management">', ['Joomla' => '3.9']],
    'Wix' => ['<meta name="generator" content="Wix.com Website Builder">', ['Wix' => null]],
]);

it('ne signale pas de CMS ancien sur une version récente', function () {
    $result = (new HtmlInspector)->inspect('<meta name="viewport" content="width=device-width"><meta name="generator" content="Joomla! 5.1"><a href="/contact">Contact</a><a href="/mentions-legales">Mentions légales</a>', 2026);

    expect($result['problems'])->toBe([]);
});

it('accepte un formulaire de contact sur l’accueil à la place d’une page contact', function () {
    $result = (new HtmlInspector)->inspect('<meta name="viewport" content="width=device-width"><form action="/envoi"><input type="email" name="e"><textarea></textarea></form><a href="/legal">Légal</a>', 2026);

    expect($result['problems'])->not->toContain(WebsiteProblem::NoContact);
});
