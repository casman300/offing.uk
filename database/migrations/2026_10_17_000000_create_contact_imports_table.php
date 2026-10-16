<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per vCard import (#14). While pending it holds the parsed
     * contacts — names, birthdays and notes only; phones, emails, addresses
     * and photos are never stored. On commit the parsed rows are dropped and
     * `result` keeps just enough to undo: the people it created and the
     * fields it filled. Pending imports are pruned after 24 hours.
     */
    public function up(): void
    {
        Schema::create('contact_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('filename')->nullable();
            $table->longText('rows')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_imports');
    }
};
