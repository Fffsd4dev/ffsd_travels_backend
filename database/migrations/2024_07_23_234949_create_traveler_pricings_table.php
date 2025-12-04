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
        Schema::create('traveler_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_offer_id')->constrained()->onDelete('cascade');
            $table->foreignId('traveler_id')->constrained()->onDelete('cascade');
            $table->string('fare_option');
            $table->string('am_traveler_pricing_id');
            $table->string('traveler_type');
            $table->string('flight_pnr');
            $table->decimal('total_price', 10, 2);
            $table->decimal('base_price', 10, 2);
            $table->json('taxes');
            $table->json('refundable_taxes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('traveler_pricings');
    }
};
