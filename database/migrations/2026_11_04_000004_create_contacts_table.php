<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // contacts : adresses email d'une entreprise (mission M7).
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email')->index(); // toujours stockée en minuscules
            $table->string('type')->default('generic'); // générique / nominatif / personnel (enum ContactType)
            $table->string('source'); // d'où vient l'adresse (enum DataSource)
            $table->timestamp('collected_at'); // collecté_le
            $table->string('verification')->default('pending'); // vérifié (enum ContactVerification)
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
