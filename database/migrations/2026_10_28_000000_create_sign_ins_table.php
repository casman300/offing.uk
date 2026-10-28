<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per browser a user is signed in on (spec §15 "Your devices").
     * Laravel's `sessions` rows expire after two idle hours while the
     * remember cookie keeps the browser signed in, so they can't answer
     * "where am I signed in?" on their own. A long-lived device cookie
     * (hashed here) ties each browser to its row.
     */
    public function up(): void
    {
        Schema::create('sign_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_hash', 64);
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('method', 20)->nullable();
            $table->boolean('remembered')->default(true);
            $table->timestamp('signed_in_at')->nullable();
            $table->timestamp('last_active_at');
            $table->timestamp('signed_out_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_ins');
    }
};
