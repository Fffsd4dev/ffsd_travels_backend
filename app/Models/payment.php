<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'payments';

    protected $fillable = [
        'amount',
        'currency',
        'user_id',
        'paid_by_email',
        'payment_reference',
        'payment_status',
        'payment_confirmed',

        // Flight order details
        'flight_pnr',
        'flight_order_id',
        'flight_am_order_id',
        'flight_search_session_id',

        // Payer details
        'paid_by_user_id',
        'paid_by_phone',
        'paid_by_company_id',
        'wallet_id',

        // Flight confirmation and payment idempotency
        'ama_client_ref',
        'idempotency_key',

        // Paystack initialization details
        'authorization_url',
        'access_code',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_confirmed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function flightOrder(): BelongsTo
    {
        return $this->belongsTo(FlightOrder::class, 'flight_order_id');
    }

    public function flightSearchSession(): BelongsTo
    {
        return $this->belongsTo(
            FlightSearchSession::class,
            'flight_search_session_id'
        );
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function paidByCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'paid_by_company_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }
}
