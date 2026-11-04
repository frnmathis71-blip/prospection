<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // companies : entreprises prospectées.
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('siren', 9)->nullable()->unique(); // unique ; peut manquer dans un import CSV
            $table->string('name'); // nom
            $table->string('naf_code', 6)->nullable()->index(); // naf, ex. 43.22A
            $table->string('address')->nullable(); // adresse
            $table->string('postal_code', 5)->nullable();
            $table->string('city')->nullable()->index(); // ville
            $table->string('department', 3)->nullable()->index(); // 21, 58, 71, 89…
            $table->decimal('latitude', 9, 6)->nullable(); // pour la recherche par rayon
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('phone', 20)->nullable(); // téléphone
            $table->string('headcount_range', 2)->nullable(); // effectif : tranche INSEE (00, 01, 02, 03, 11…)
            $table->date('founded_on')->nullable(); // date_creation
            $table->boolean('is_active')->default(true); // état administratif : active ou fermée
            $table->string('status')->default('new')->index(); // statut (enum ProspectStatus)
            $table->unsignedTinyInteger('score')->nullable()->index(); // score 0-100 (mission M6)
            $table->date('next_action_on')->nullable(); // date de prochaine action
            $table->decimal('quote_amount', 10, 2)->nullable(); // montant du devis
            $table->string('source'); // origine de la donnée (enum DataSource)
            $table->foreignId('search_id')->nullable()->constrained()->nullOnDelete(); // recherche d'origine
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
