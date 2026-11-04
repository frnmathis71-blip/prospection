<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M3 : domaine propre du site (sans www.), pour dédoublonner les imports.
        // Null pour un réseau social ou un annuaire (voir App\Support\Domain).
        Schema::table('websites', function (Blueprint $table) {
            $table->string('domain')->nullable()->index()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('domain');
        });
    }
};
