<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // emails : chaque email de prospection prévu ou envoyé (missions M9-M10).
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete(); // campagne
            $table->foreignId('sequence_step_id')->nullable()->constrained()->nullOnDelete(); // étape
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete(); // taux par template et A/B
            $table->string('subject')->nullable();
            $table->string('status')->default('queued')->index(); // envoyé, rebond, répondu… (enum EmailStatus)
            $table->timestamp('sent_at')->nullable(); // envoyé_le
            $table->string('message_id')->nullable()->unique(); // Message-ID, pour relier les réponses
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
