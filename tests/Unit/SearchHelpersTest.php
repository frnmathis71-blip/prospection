<?php

use App\Services\RobotsTxt;
use App\Support\CompanyName;

it('applique les règles de robots.txt', function (string $robots, string $path, bool $allowed) {
    expect(RobotsTxt::isAllowed($robots, $path, 'CodenyrProspection/1.0 (+https://codenyr.fr)'))->toBe($allowed);
})->with([
    'aucune règle' => ['', '/', true],
    'tout interdit' => ["User-agent: *\nDisallow: /", '/', false],
    'dossier interdit' => ["User-agent: *\nDisallow: /admin/", '/', true],
    'Disallow vide' => ["User-agent: *\nDisallow:", '/', true],
    'Allow plus précis' => ["User-agent: *\nDisallow: /\nAllow: /contact", '/contact', true],
    'notre robot interdit' => ["User-agent: CodenyrProspection\nDisallow: /\n\nUser-agent: *\nAllow: /", '/', false],
    'autre robot interdit' => ["User-agent: Googlebot\nDisallow: /", '/', true],
]);

it('rapproche deux noms d’entreprise sur leurs mots distinctifs', function () {
    expect(CompanyName::similarity('SARL PLOMBERIE MARTIN', 'Plomberie Martin'))->toBe(1)
        ->and(CompanyName::similarity('Plomberie Martin', 'Plomberie Durand'))->toBe(0)
        ->and(CompanyName::similarity('Boulangerie', 'Boulangerie du Coin'))->toBe(0)
        ->and(CompanyName::distinctiveWords('EARL du Domaine des Grands Prés'))->toBe(['grands', 'pres'])
        ->and(CompanyName::distinctiveWords('GAEC de la Ferme du Vieux Moulin'))->toBe(['vieux', 'moulin'])
        ->and(CompanyName::distinctiveWords('Cave des Vignerons de Buxy'))->toBe(['buxy']);
});
