<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M8 — Templates : texte du bouton vers codenyr.fr (un seul appel à l'action par email).
 * Vide = texte par défaut de config('prospection.emails.cta_label').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->string('cta_label')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('cta_label');
        });
    }
};
