<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message lu dans la boîte d'envoi (M10), conservé pour ne le traiter qu'une fois.
 * Seuls l'expéditeur et l'objet sont gardés (pas le contenu : minimisation).
 */
#[Fillable(['message_key', 'kind', 'email_id', 'from', 'subject', 'received_at'])]
class InboundMessage extends Model
{
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
