<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FlightOrders extends Model
{
    use HasFactory;

    protected $fillable = [
        'pnr',
        'type',
        'queueing_office_id',
        'flight_search_session_id',
        'idempotency_key',
    ];

    public function associatedRecords(): HasMany
    {
        return $this->hasMany(AssociatedRecords::class, 'flight_order_id');
    }

    public function flightOffers(): HasMany
    {
        return $this->hasMany(FlightOffer::class, 'flight_order_id');
    }

    public function travelers(): HasMany
    {
        return $this->hasMany(Traveler::class, 'flight_order_id');
    }

    public function ticketingAgreement(): HasOne
    {
        return $this->hasOne(TicketingAgreement::class, 'flight_order_id');
    }

    public function automatedProcesses(): HasMany
    {
        return $this->hasMany(AutomatedProcess::class, 'flight_order_id');
    }
}
