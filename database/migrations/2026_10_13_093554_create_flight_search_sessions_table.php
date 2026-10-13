<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_search_sessions', function (Blueprint $table) {
            $table->id();

            // Stable reference shared across search, confirmation and booking.
            $table->string('ama_client_ref', 40)->unique();

            // Optional authenticated user.
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('status', 30)
                ->default('searching')
                ->index();

            $table->char('search_fingerprint', 64)
                ->nullable()
                ->index();

            $table->string('confirmed_offer_key', 100)->nullable();

            $table->uuid('idempotency_key')->nullable()->unique();

            $table->json('search_criteria')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_search_sessions');
    }
};
