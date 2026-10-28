<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per sign-in (spec §15 "Your devices"), each with its own
     * remember-me token (only its hash is kept), so one browser can be
     * signed out without touching the others. Laravel's single shared
     * `users.remember_token` can't do that, and `sessions` rows expire
     * after two idle hours while the remember cookie keeps going.
     */
    public function up(): void
    {
        Schema::create('sign_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('remember_hash', 64)->nullable()->unique();
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('method', 20)->nullable();
            $table->boolean('remembered')->default(true);
            $table->timestamp('signed_in_at')->nullable();
            $table->timestamp('last_active_at');
            $table->timestamp('signed_out_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'signed_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_ins');
    }
};
