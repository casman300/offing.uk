<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rollout stages chosen on /admin/features. A flag with no row follows the
 * stage declared in its class (App\Features\StageOverrides).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_stages', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->string('stage');
            $table->unsignedTinyInteger('percentage')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_stages');
    }
};
