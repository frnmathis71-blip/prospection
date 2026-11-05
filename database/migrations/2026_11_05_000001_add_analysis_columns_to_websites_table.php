<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M5 : résultat brut de l'analyse d'un site.
        Schema::table('websites', function (Blueprint $table) {
            $table->string('final_url')->nullable(); // adresse après redirections (http → https, www…)
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('analysis_note')->nullable(); // raison d'un « hors ligne » ou d'une analyse impossible
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['final_url', 'http_status', 'analysis_note']);
        });
    }
};
