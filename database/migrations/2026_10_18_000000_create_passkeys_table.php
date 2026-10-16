<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec §6/§15: WebAuthn passkeys, a way to sign in on their own (#9).
     *
     * `credential_id` is the base64url credential ID, which is how an
     * assertion finds its row. `credential` is the whole serialized
     * webauthn-lib CredentialRecord (public key, counter, transports, backup
     * flags), so nothing the verifier needs is ever lost to column mapping.
     */
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('credential_id', 255)->unique();
            $table->text('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
