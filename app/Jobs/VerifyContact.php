<?php

namespace App\Jobs;

use App\Enums\ContactVerification;
use App\Models\Contact;
use App\Services\CompanyScorer;
use App\Services\EmailVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Vérifie une adresse « À vérifier » (syntaxe + adresse jetable + MX), puis recalcule le score :
 * une adresse invalide ne compte plus dans la joignabilité (mission M7).
 */
class VerifyContact implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(public Contact $contact)
    {
        $this->onQueue(EnrichCompany::QUEUE);
    }

    public function handle(EmailVerifier $verifier, CompanyScorer $scorer): void
    {
        $contact = $this->contact->fresh();

        if ($contact === null || $contact->verification !== ContactVerification::Pending) {
            return;
        }

        ['status' => $status, 'reason' => $reason] = $verifier->verify($contact->email);

        $contact->update(['verification' => $status, 'verification_note' => $reason, 'verified_at' => now()]);

        if ($company = $contact->company) {
            $scorer->refresh($company);
        }
    }
}
