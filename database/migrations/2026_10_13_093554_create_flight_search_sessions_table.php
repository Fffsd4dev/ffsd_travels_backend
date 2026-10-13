
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

            // Public reference shared across search, confirmation and booking.
            $table->string('ama_client_ref', 40)->unique();

            // The user who initiated the search, if authenticated.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Current lifecycle state.
            $table->string('status', 30)->default('searching')->index();

            // Optional fingerprint for detecting equivalent search requests.
            $table->char('search_fingerprint', 64)->nullable()->index();

            // Cache key for the confirmed offer; do not store large offer payloads here.
            $table->string('confirmed_offer_key', 100)->nullable();

            // Idempotency key associated with the confirmed offer/booking flow.
            $table->uuid('idempotency_key')->nullable()->unique();

            // Search criteria, not the full Amadeus response.
            $table->json('search_criteria')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_search_sessions');
    }
};
