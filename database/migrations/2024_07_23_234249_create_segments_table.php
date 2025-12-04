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
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('itinerary_id')->constrained()->onDelete('cascade');
            $table->string('am_segment_id');
            $table->json('departure');
            $table->json('arrival');
            $table->string('carrier_code');
            $table->string('number');
            $table->json('aircraft');
            $table->string('duration');
            $table->integer('number_of_stops');
            // In the migration file
$table->json('co2_emissions')->nullable(); // Allows the column to accept NULL values

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
