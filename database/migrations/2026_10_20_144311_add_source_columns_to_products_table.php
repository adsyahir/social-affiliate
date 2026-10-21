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
        Schema::table('products', function (Blueprint $table) {
            // The program's own product page; drivers with API access build tracked links from it.
            $table->text('product_url')->nullable()->after('affiliate_link');
            // The program's product ID, so importing the same product twice updates nothing.
            $table->string('external_id')->nullable()->after('affiliate_account_id');

            $table->unique(['affiliate_account_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['affiliate_account_id', 'external_id']);
            $table->dropColumn(['product_url', 'external_id']);
        });
    }
};
