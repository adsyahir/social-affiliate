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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            // Nullable so non-sales posts can exist later without a campaign or product.
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('social_account_id')->constrained()->restrictOnDelete();
            $table->text('content');
            // The reply that carries the affiliate link and disclosure; the main post never has the link.
            $table->text('link_reply_content')->nullable();
            $table->string('status')->default('draft');
            $table->string('external_post_id')->nullable();
            $table->string('link_reply_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['social_account_id', 'status']);
            $table->index(['status', 'published_at']);
        });

        Schema::create('post_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('replies')->default(0);
            $table->unsignedBigInteger('reposts')->default(0);
            $table->timestamp('fetched_at');

            $table->index(['post_id', 'fetched_at']);
        });

        Schema::create('post_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('external_reply_id');
            $table->string('author_username')->nullable();
            $table->text('text');
            $table->string('category')->nullable();
            $table->text('ai_draft')->nullable();
            $table->string('status')->default('new')->index();
            $table->string('our_reply_id')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'external_reply_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_replies');
        Schema::dropIfExists('post_insights');
        Schema::dropIfExists('posts');
    }
};
