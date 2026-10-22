<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email changes (security audit H1) reuse magic links rather than adding a
 * second token type: `purpose` says what a link may do, `email` is the
 * address it's about (the new one to confirm, or the old one to revert to).
 * Existing rows are sign-in links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magic_links', function (Blueprint $table) {
            $table->string('purpose', 32)->default('login')->after('user_id');
            $table->string('email')->nullable()->after('purpose');
            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('magic_links', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'purpose']);
            $table->dropColumn(['purpose', 'email']);
        });
    }
};
