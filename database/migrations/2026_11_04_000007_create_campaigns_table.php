<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // campaigns : recherche ou liste ciblée + séquence + quotas (mission M9).
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('search_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sequence_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('daily_quota')->default(10); // préchauffage : ~10 envois/jour au départ
            $table->unsignedSmallInteger('hourly_quota')->default(3);
            $table->string('status')->default('draft'); // enum CampaignStatus : toujours créée en brouillon
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
