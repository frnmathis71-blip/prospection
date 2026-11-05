<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M9 — Envoi conforme.
 *
 * campaigns.criteria : liste ciblée (recherche, score minimum, état du site, départements, secteurs, nombre maximum).
 * emails.company_id  : entreprise visée, pour garantir un seul contact par campagne et par étape.
 * emails.note        : raison d'une annulation au dernier moment (opposition, adresse invalide…) ou d'un échec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('criteria')->nullable()->after('sequence_id');
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('contact_id')->constrained()->cascadeOnDelete();
            $table->string('note')->nullable()->after('message_id');

            // Une entreprise n'est jamais contactée deux fois par la même campagne à la même étape.
            $table->unique(['campaign_id', 'company_id', 'sequence_step_id']);
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'company_id', 'sequence_step_id']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('note');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('criteria');
        });
    }
};
