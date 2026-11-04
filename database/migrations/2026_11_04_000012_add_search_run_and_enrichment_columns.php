<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M4 : suivi du dernier lancement d'une recherche.
        Schema::table('searches', function (Blueprint $table) {
            $table->string('status')->nullable(); // enum SearchStatus
            $table->json('last_run_stats')->nullable(); // trouvées, ajoutées, déjà connues, exclues…
            $table->text('last_error')->nullable();
        });

        // M4 : enrichissement gratuit (OpenStreetMap, devinette de domaine).
        Schema::table('companies', function (Blueprint $table) {
            $table->json('osm_signals')->nullable(); // horaires, réseaux sociaux… (© OpenStreetMap)
            $table->timestamp('enriched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_run_stats', 'last_error']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['osm_signals', 'enriched_at']);
        });
    }
};
