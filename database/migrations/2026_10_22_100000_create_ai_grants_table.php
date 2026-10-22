<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connect Claude (spec §12): what the user chose on the consent screen,
 * one row per connected OAuth client. Revoking deletes the row (and the
 * tokens); the audit log keeps the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('oauth_client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->string('level', 16);
            $table->boolean('include_private')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'oauth_client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_grants');
    }
};
