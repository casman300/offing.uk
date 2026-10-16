<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec §6/§8: secret outbound ICS URLs. The subject is a morph so person,
     * group and share feeds can follow without a migration; only
     * subject=user, scope=full exists today (#15).
     *
     * Only the SHA-256 of the token is stored, the same as magic links, so a
     * URL is shown once when it's minted and never again. `name` and the
     * last-used columns are what let someone tell their links apart later
     * ("Charlie's iPhone, last fetched by Google an hour ago").
     */
    public function up(): void
    {
        Schema::create('feed_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('name', 50);
            $table->string('token_hash', 64)->unique();
            $table->string('scope', 16)->default('full');
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_user_agent')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_tokens');
    }
};
