<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // templates : modèles d'email (mission M8).
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // nom
            $table->string('angle'); // sans site / site obsolète (enum TemplateAngle)
            $table->string('sector')->nullable(); // variante par secteur : artisan, restaurant…
            $table->string('variant', 1)->nullable(); // variante A/B de l'objet
            $table->string('subject'); // objet
            $table->text('body'); // corps
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
