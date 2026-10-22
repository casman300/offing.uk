<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crews (spec §6/§7): the one sharing primitive for jointly-owned
     * things. Roles are captain / crew / passenger; a passenger is a
     * crew-owned person with no account.
     *
     * - crew_members: exactly one of user_id / person_id. Leaving sets
     *   left_at rather than deleting, so rejoining reuses the row (hence the
     *   uniques) and history keeps who was aboard.
     * - crew_invites: invitations by email, accepted or declined by the
     *   account with that email from its own /crew page. The captain sees
     *   the same thing whether or not the email has an account, so captains
     *   can't probe for users. token_hash is for the emailed link (invite =
     *   signup, spec §15), which comes in a later slice.
     */
    public function up(): void
    {
        Schema::create('crews', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crew_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['crew_id', 'user_id']);
            $table->unique(['crew_id', 'person_id']);
            $table->index(['user_id', 'left_at']);
        });

        Schema::create('crew_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 16);
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_invites');
        Schema::dropIfExists('crew_members');
        Schema::dropIfExists('crews');
    }
};
