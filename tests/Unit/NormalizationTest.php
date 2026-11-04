<?php

use App\Support\Domain;
use App\Support\Phone;

it('normalise les numéros de téléphone français', function (?string $input, ?string $expected) {
    expect(Phone::normalize($input))->toBe($expected);
})->with([
    ['03 85 12 34 56', '0385123456'],
    ['03.85.12.34.56', '0385123456'],
    ['+33 3 85 12 34 56', '0385123456'],
    ['+33 (0)3 85 12 34 56', '0385123456'],
    ['0033385123456', '0385123456'],
    ['+41 22 123 45 67', '+41221234567'],
    ['', null],
    [null, null],
    ['n/a', null],
]);

it('extrait le domaine propre d’une adresse de site', function (?string $url, ?string $expected) {
    expect(Domain::fromUrl($url))->toBe($expected);
})->with([
    ['https://www.Plombier-Martin.fr/contact', 'plombier-martin.fr'],
    ['plombier-martin.fr', 'plombier-martin.fr'],
    ['http://boutique.plombier-martin.fr', 'boutique.plombier-martin.fr'],
    ['https://www.facebook.com/plombier', null],
    ['https://plombier.wixsite.com/site', null],
    ['https://www.pagesjaunes.fr/pros/123', null],
    ['pas une url', null],
    [null, null],
]);

it('ignore les messageries grand public pour le domaine d’un email', function (string $email, ?string $expected) {
    expect(Domain::fromEmail($email))->toBe($expected);
})->with([
    ['contact@plombier-martin.fr', 'plombier-martin.fr'],
    ['Devis@Plombier-Martin.FR', 'plombier-martin.fr'],
    ['plombier.martin@gmail.com', null],
    ['martin@orange.fr', null],
    ['pas-un-email', null],
]);

it('garde un seul numéro quand le champ en contient plusieurs, en préférant le fixe', function (string $input, ?string $expected) {
    expect(Phone::pickBusiness($input))->toBe($expected);
})->with([
    'fixe puis portable' => ['03 85 00 00 10 / 06 00 00 00 20', '0385000010'],
    'portable puis fixe' => ['06 00 00 00 20 / 03 85 00 00 10', '0385000010'],
    'deux portables : le premier' => ['06 00 00 00 30 / 06 00 00 00 40', '0600000030'],
    'numéro 09 (box) gardé comme fixe' => ['09 80 00 00 50 / 06 00 00 00 60', '0980000050'],
    'séparateur point-virgule' => ['0385000001 ; 0612345678', '0385000001'],
    'séparateur « ou »' => ['06 12 34 56 78 ou 03 80 00 00 01', '0380000001'],
    'un seul numéro' => ['03 85 12 34 56', '0385123456'],
    'rien de valide' => ['12 34', '1234'],
]);
