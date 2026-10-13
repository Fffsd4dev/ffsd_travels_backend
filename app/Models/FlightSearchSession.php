<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightSearchSession extends Model
{
    protected $fillable = [
        'ama_client_ref',
        'user_id',
        'status',
        'search_fingerprint',
        'confirmation_fingerprint',
        'confirmed_offer_key',
        'idempotency_key',
        'search_criteria',
        'confirmed_at',
        'expires_at',

        // Confirmed flight pricing
        'flight_fee',
        'flight_currency',
        'service_fee',
        'total_payable',
    ];

    protected function casts(): array
    {
        return [
            'search_criteria' => 'array',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',

            // Monetary values
            'flight_fee' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'total_payable' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
