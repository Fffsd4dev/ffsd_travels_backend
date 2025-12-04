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
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('Fname'); // FName of the enquirer
             $table->string('Lname'); // LName of the enquirer
            $table->string('email'); // Email of the enquirer
            $table->string('phone'); // Phone number of the enquirer
            $table->date('travel_date'); // Travel date
            $table->date('return_date'); // Return date
            $table->foreignId('advert_id')
                ->constrained('advertisements')
                ->onDelete('cascade'); // Cascade on delete
            $table->timestamps(); // Created at and Updated at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
