<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Origine d'une donnée : à conserver pour répondre à « d'où tenez-vous mon adresse ? ».
 */
enum DataSource: string implements HasColor, HasLabel
{
    case CsvImport = 'csv_import';
    case RechercheEntreprises = 'recherche_entreprises';
    case Sirene = 'sirene';
    case OpenStreetMap = 'openstreetmap';
    case Website = 'website';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::CsvImport => 'Import CSV',
            self::RechercheEntreprises => "API Recherche d'entreprises",
            self::Sirene => 'API Sirene (INSEE)',
            self::OpenStreetMap => 'OpenStreetMap',
            self::Website => "Site de l'entreprise",
            self::Manual => 'Saisie manuelle',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CsvImport => 'gray',
            self::RechercheEntreprises => 'info',
            self::Sirene => 'info',
            self::OpenStreetMap => 'info',
            self::Website => 'success',
            self::Manual => 'gray',
        };
    }
}
