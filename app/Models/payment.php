<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount',
        'user_id',
        'paid_by_email',
        'payment_reference',
        'payment_status',
        'flight_pnr',
        'flight_order_id',
        'paid_by_user_id',
        'paid_by_company_id',
        'flight_am_order_id',
        'wallet_id',
    ];

    // Define relationships if needed, e.g., belongsTo for foreign keys
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function flightOrder()
    {
        return $this->belongsTo(FlightOrder::class);
    }

    public function paidByUser()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function paidByCompany()
    {
        return $this->belongsTo(Company::class, 'paid_by_company_id');
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
