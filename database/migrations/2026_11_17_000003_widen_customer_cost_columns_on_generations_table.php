<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer costs are provider cost plus a 25% markup, which needs two more decimal places
     * than the provider cost.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->decimal('estimated_customer_cost', 18, 10)->default(0)->change();
            $table->decimal('actual_customer_cost', 18, 10)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->decimal('estimated_customer_cost', 18, 8)->default(0)->change();
            $table->decimal('actual_customer_cost', 18, 8)->nullable()->change();
        });
    }
};
