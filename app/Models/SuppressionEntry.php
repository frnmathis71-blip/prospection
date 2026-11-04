<?php

namespace App\Models;

use App\Enums\SuppressionReason;
use Database\Factories\SuppressionEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Entrée de la liste d'opposition (table suppression_list) : une adresse ou un domaine
 * qui ne doit plus jamais être contacté, même après réimport.
 *
 * Cette table ne doit JAMAIS être purgée (cf. CLAUDE.md, interdits).
 */
#[Fillable(['email', 'domain', 'reason'])]
class SuppressionEntry extends Model
{
    /** @use HasFactory<SuppressionEntryFactory> */
    use HasFactory;

    protected $table = 'suppression_list';

    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value) => self::normalize($value));
    }

    protected function domain(): Attribute
    {
        return Attribute::set(fn (?string $value) => self::normalize($value === null ? null : ltrim($value, '@')));
    }

    /**
     * Vrai si l'adresse, ou son domaine, est sur la liste d'opposition (sans tenir compte de la casse).
     */
    public static function blocks(string $email): bool
    {
        $email = self::normalize($email);
        $domain = Str::after($email, '@');

        return self::query()
            ->where('email', $email)
            ->orWhere('domain', $domain)
            ->exists();
    }

    private static function normalize(?string $value): ?string
    {
        return blank($value) ? null : mb_strtolower(trim($value));
    }
}
