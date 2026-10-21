<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            // The AI model that writes this account's posts; null uses the configured default.
            $table->string('ai_model')->nullable()->after('persona');
        });

        Schema::table('posts', function (Blueprint $table) {
            // Model, token usage and attempts for the draft, for cost tracking.
            $table->jsonb('generation')->nullable()->after('link_reply_content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('generation');
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('ai_model');
        });
    }
};
