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
        Schema::create('automated_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_order_id')->constrained()->onDelete('cascade');
            $table->string('code');
            $table->integer('queue_number');
            $table->string('queue_category');
            $table->string('office_id');
            $table->timestamps();});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automated_processes');
    }
};
