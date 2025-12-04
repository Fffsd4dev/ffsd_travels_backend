<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;
    protected $fillable =['invoice_sequence_id','company_id',
    'flight_order_id','pnr','invoice_date','total_amount',
    'user_id','payment_status','paid_by_user_id'];
}
