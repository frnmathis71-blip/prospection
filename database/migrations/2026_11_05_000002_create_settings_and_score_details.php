<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M6 : réglages modifiables depuis l'interface (poids du score…).
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // M6 : détail du score (« pourquoi ce score ? ») et date du dernier calcul.
        Schema::table('companies', function (Blueprint $table) {
            $table->json('score_details')->nullable();
            $table->timestamp('scored_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['score_details', 'scored_at']);
        });
    }
};
