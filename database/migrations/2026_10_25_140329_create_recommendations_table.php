<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replies you write yourself to people on Threads who asked for a recommendation.
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('threads_url', 500);
            $table->text('question');
            $table->text('reply_text')->nullable();
            $table->string('status')->default('choosing');
            $table->string('telegram_chat_id')->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->timestamp('replied_at')->nullable()->index();
            $table->timestamp('link_sent_at')->nullable();
            $table->timestamps();

            $table->index(['telegram_chat_id', 'telegram_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
