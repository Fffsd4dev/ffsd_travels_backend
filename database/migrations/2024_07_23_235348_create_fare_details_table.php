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
        Schema::create('fare_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('segment_id')->constrained()->onDelete('cascade');
            $table->string('am_segment_id');//amadeus segment_id
            $table->string('cabin');
            $table->string('fare_basis');
            $table->string('class');
            $table->foreignId('flight_order_id')->constrained('flight_orders')->onDelete('cascade');
            $table->json('included_checked_bags');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fare_details');
    }
};
