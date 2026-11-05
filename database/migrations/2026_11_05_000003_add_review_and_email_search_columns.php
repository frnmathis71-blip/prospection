<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 — Recherche et vérification des emails.
 *
 * contacts.verification_note : raison d'un échec de vérification (syntaxe, adresse jetable, pas de MX).
 * contacts.reviewed_at       : adresse personnelle (gmail.com, orange.fr…) validée à la main pour la prospection.
 * contacts.source_url        : page du site où l'adresse a été trouvée (« d'où tenez-vous mon adresse ? »).
 * companies.emails_searched_at : date du dernier crawl du site à la recherche d'emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('verification_note')->nullable()->after('verified_at');
            $table->timestamp('reviewed_at')->nullable()->after('verification_note');
            $table->string('source_url', 2048)->nullable()->after('source');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('emails_searched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['verification_note', 'reviewed_at', 'source_url']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('emails_searched_at');
        });
    }
};
