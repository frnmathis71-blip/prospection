<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // sequences / sequence_steps : séquence J0 / J+4 / J+12 (mission M10).
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position'); // 1, 2, 3 : jamais plus de 3 emails par prospect
            $table->unsignedSmallInteger('delay_days'); // délai en jours ouvrés après J0
            $table->foreignId('template_id')->constrained()->restrictOnDelete(); // template de l'étape
            $table->timestamps();

            $table->unique(['sequence_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_steps');
        Schema::dropIfExists('sequences');
    }
};
