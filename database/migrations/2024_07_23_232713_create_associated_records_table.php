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
        Schema::create('associated_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_order_id')->constrained()->onDelete('cascade');
            $table->string('reference');
            $table->dateTime('creation_date');
            $table->string('origin_system_code');
            $table->string('flight_offer_id');
            $table->timestamps();;
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('associated_records');
    }
};
