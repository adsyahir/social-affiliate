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
        Schema::table('posts', function (Blueprint $table) {
            // The approval message in Telegram, edited as the draft moves on.
            $table->string('telegram_chat_id')->nullable()->after('generation');
            $table->unsignedBigInteger('telegram_message_id')->nullable()->after('telegram_chat_id');
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->string('approved_by')->nullable()->after('approved_at');
            $table->text('permalink')->nullable()->after('external_post_id');

            $table->index(['telegram_chat_id', 'telegram_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['telegram_chat_id', 'telegram_message_id']);
            $table->dropColumn(['telegram_chat_id', 'telegram_message_id', 'approved_at', 'approved_by', 'permalink']);
        });
    }
};
