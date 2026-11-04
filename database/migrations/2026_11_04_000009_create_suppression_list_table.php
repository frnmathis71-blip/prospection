<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // suppression_list : liste d'opposition PERMANENTE.
        // Elle ne doit jamais être purgée, ni par la purge RGPD, ni par une réinitialisation.
        Schema::create('suppression_list', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable()->unique(); // adresse bloquée, en minuscules
            $table->string('domain')->nullable()->unique(); // ou domaine entier bloqué, en minuscules
            $table->string('reason'); // raison (enum SuppressionReason)
            $table->timestamps(); // created_at = date d'inscription
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppression_list');
    }
};
