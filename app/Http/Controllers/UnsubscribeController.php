<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\EmailStatus;
use App\Enums\ProspectStatus;
use App\Enums\SuppressionReason;
use App\Models\Contact;
use App\Models\Email;
use App\Models\SuppressionEntry;
use App\Services\CircuitBreaker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Désinscription en un clic (lien signé du pied de page, en-tête List-Unsubscribe-Post).
 * Sans connexion : l'adresse est ajoutée à la liste d'opposition, définitivement.
 */
class UnsubscribeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $email = mb_strtolower((string) base64_decode(strtr((string) $request->query('e'), '-_', '+/'), true));

        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 404);

        if (! SuppressionEntry::query()->where('email', $email)->exists()) {
            SuppressionEntry::create(['email' => $email, 'reason' => SuppressionReason::Unsubscribed]);
        }

        // M10 : les emails encore en file (relances comprises) sont annulés.
        Email::query()
            ->where('status', EmailStatus::Queued)
            ->whereHas('contact', fn ($query) => $query->where('email', $email))
            ->update(['status' => EmailStatus::Cancelled, 'note' => 'Désinscription : séquence arrêtée.']);

        Contact::query()->where('email', $email)->with('company')->get()->each(function (Contact $contact) {
            $company = $contact->company;

            if ($company && ! $company->status->isBlocking()) {
                $company->update(['status' => ProspectStatus::Unsubscribed]);
                $company->activities()->create([
                    'type' => ActivityType::StatusChange,
                    'content' => "Désinscription de {$contact->email} (lien de l’email).",
                    'occurred_at' => now(),
                ]);
            }
        });

        // M11 : trop de désinscriptions = toutes les campagnes en pause.
        app(CircuitBreaker::class)->check();

        return response()->view('unsubscribed', ['email' => $email]);
    }
}
