<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // websites : état et analyse du site d'une entreprise (mission M5).
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete(); // un site par entreprise
            $table->string('url')->nullable();
            $table->string('state')->default('unknown')->index(); // enum WebsiteState
            $table->boolean('https')->nullable();
            $table->boolean('responsive')->nullable();
            $table->unsignedTinyInteger('pagespeed')->nullable(); // score mobile 0-100
            $table->unsignedSmallInteger('copyright_year')->nullable();
            $table->json('technologies')->nullable(); // technos : generator, jQuery…
            $table->json('problems')->nullable(); // problèmes détectés, en clair
            $table->string('screenshot_path')->nullable(); // capture
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
