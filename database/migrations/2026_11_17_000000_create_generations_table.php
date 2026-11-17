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
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_generation_id', 100)->nullable()->unique();
            $table->text('prompt');
            $table->string('size', 30);
            $table->string('quality', 30);
            $table->json('reference_image_urls')->nullable();
            $table->string('status', 20)->default('queued');
            $table->decimal('estimated_provider_cost', 18, 8)->default(0);
            $table->decimal('estimated_customer_cost', 18, 8)->default(0);
            $table->decimal('actual_provider_cost', 18, 8)->nullable();
            $table->decimal('actual_customer_cost', 18, 8)->nullable();
            $table->json('token_usage')->nullable();
            $table->json('provider_rates_snapshot')->nullable();
            $table->text('image_url')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
