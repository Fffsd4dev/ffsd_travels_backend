<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceSequence extends Model
{
    use HasFactory;
    protected $fillable =['invoice_id','invoice_number','fy'];
    //fy refers to the financial year and invoice_id refers to the id on the invoice table.
}
