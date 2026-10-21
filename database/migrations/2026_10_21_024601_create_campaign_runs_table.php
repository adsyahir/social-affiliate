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
        // One row per scheduled batch, written before any job runs, so slots are never used twice.
        Schema::create('campaign_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            // dispatched: drafts are being written; no_products: nothing left to post.
            $table->string('status');
            // manual: started from the admin; it does not use up one of the day's slots.
            $table->string('trigger')->default('schedule');
            $table->unsignedSmallInteger('accounts')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['campaign_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_runs');
    }
};
