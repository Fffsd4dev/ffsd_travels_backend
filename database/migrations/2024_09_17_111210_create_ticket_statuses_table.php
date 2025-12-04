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
        Schema::create('ticket_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('flight_order_id');
            $table->string('pnr');
            $table->string('ticket_status')->default('not done');
            $table->timestamps();

            // Adding the foreign key constraint
            $table->foreign('flight_order_id')->references('id')->on('flight_orders')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_statuses');
    }
};
