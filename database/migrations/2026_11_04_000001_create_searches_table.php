<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // searches : recherches sauvegardées et relançables (mission M4).
        Schema::create('searches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('criteria'); // critères : zone, codes NAF, effectif, ancienneté…
            $table->string('recurrence')->nullable(); // ex. « weekly » ; null = lancement manuel
            $table->timestamp('last_run_at')->nullable(); // dernier_lancement
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('searches');
    }
};
