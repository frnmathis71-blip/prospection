<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M10 — Relances, réponses et rebonds.
 *
 * inbound_messages : messages lus dans la boîte d'envoi (IMAP), traités une seule fois.
 *   kind : reply (réponse), auto_reply (absence), hard_bounce, soft_bounce, ignored.
 * emails.retries   : nouvelles tentatives après un rebond temporaire (une seule permise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->string('message_key')->unique(); // Message-ID, ou empreinte du message s'il n'en a pas
            $table->string('kind')->index();
            $table->foreignId('email_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from')->nullable();
            $table->string('subject')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->unsignedTinyInteger('retries')->default(0)->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn('retries');
        });

        Schema::dropIfExists('inbound_messages');
    }
};
