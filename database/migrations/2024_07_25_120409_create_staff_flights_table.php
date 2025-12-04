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

        Schema::create('staff_flights', function (Blueprint $table) {
            $table->id();
            $table->string('staff_full_name');
            $table->string('staff_id');
            $table->string('company_id');
            $table->string('pnr');
            $table->dateTime('booked_time');
            $table->json('flight_details');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_flights');
    }
};
