<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec §6/§7: a person is owned by a user (their private address book)
     * or a crew (shared with it — a passenger). `owner_user_id` becomes an
     * `owner` morph; every existing row is user-owned, so it's a straight
     * copy. Done in three steps (add, copy, drop) so no row is ever ownerless.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('owner_type')->nullable()->after('id');
            $table->unsignedBigInteger('owner_id')->nullable()->after('owner_type');
        });

        DB::table('people')->update([
            'owner_type' => (new User)->getMorphClass(),
            'owner_id' => DB::raw('owner_user_id'),
        ]);

        // Foreign key first: on MySQL it may be leaning on the composite
        // index, which then can't be dropped while the key exists.
        Schema::table('people', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
        });

        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['owner_user_id', 'deleted_at']);
            $table->dropColumn('owner_user_id');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->string('owner_type')->nullable(false)->change();
            $table->unsignedBigInteger('owner_id')->nullable(false)->change();
            $table->index(['owner_type', 'owner_id', 'deleted_at']);
        });
    }

    /**
     * Crew-owned people have nowhere to go in the old shape, so they're
     * dropped; user-owned ones are copied back.
     */
    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
        });

        $userType = (new User)->getMorphClass();
        DB::table('people')->where('owner_type', '!=', $userType)->delete();
        DB::table('people')->update(['owner_user_id' => DB::raw('owner_id')]);

        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['owner_type', 'owner_id', 'deleted_at']);
            $table->dropColumn(['owner_type', 'owner_id']);
            $table->index(['owner_user_id', 'deleted_at']);
        });
    }
};
