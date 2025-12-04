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
        Schema::create('mark_ups', function (Blueprint $table) {
            $table->id();
            $table->string('fee_name');
            $table->decimal('fee_percentage', 10, 2);
            $table->bigInteger('created_by_user_id'); // Corrected from bigInt to bigInteger
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mark_ups');
    }
};
