<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('ama_client_ref', 100)->nullable()->index();
            $table->foreignId('flight_search_session_id')
                ->nullable()
                ->constrained('flight_search_sessions')
                ->nullOnDelete();
            $table->string('currency', 3)->nullable();
            $table->text('authorization_url')->nullable();
            $table->string('access_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign([
                'flight_search_session_id',
            ]);

            $table->dropUnique([
                'payments_idempotency_key_unique',
            ]);

            $table->dropColumn([
                'idempotency_key',
                'ama_client_ref',
                'flight_search_session_id',
                'currency',
                'authorization_url',
                'access_code',
            ]);
        });
    }
};