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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_account_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('affiliate_link');
            $table->text('notes')->nullable();
            $table->text('image_url')->nullable();
            $table->string('status')->default('available');
            // Only products you actually used may get first-person claims, built from review_notes.
            $table->boolean('used_personally')->default(false);
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['affiliate_account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
