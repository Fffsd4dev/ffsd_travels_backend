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
        Schema::create('flight_offers', function (Blueprint $table) {
            $table->id();
            $table->string('flight_order_id');
            $table->string('type');
            $table->string('am_flight_offer_id');
            $table->string('source');
            $table->boolean('non_homogeneous');
            $table->date('last_ticketing_date');
            $table->json('price');
            $table->json('pricing_options');
            $table->json('validating_airline_codes');
            $table->timestamps();;
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_offers');
    }
};
