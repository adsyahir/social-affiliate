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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('affiliate_account_id')->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('posts_per_day')->default(1);
            $table->timestamps();
        });

        Schema::create('campaign_social_account', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['campaign_id', 'social_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_social_account');
        Schema::dropIfExists('campaigns');
    }
};
