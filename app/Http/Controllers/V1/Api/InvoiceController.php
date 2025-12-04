<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use LaravelDaily\Invoices\Invoice;
use LaravelDaily\Invoices\Classes\Buyer;
use LaravelDaily\Invoices\Classes\InvoiceItem;

class InvoiceController extends Controller
{
    //

public function generate(){
    //data should contain the details of the buyer as well as that of the flight
    $customer = new Buyer([
        'name'          => 'John Doe',
        'custom_fields' => [
            'email' => 'test@example.com',
        ],
    ]);
    
    $item = InvoiceItem::make('Service 1')->pricePerUnit(2);
    
    $invoice = Invoice::make()
        ->buyer($customer)
        ->discountByPercent(10)
        ->taxRate(15)
        ->shipping(1.99)
        ->addItem($item)
        ->logo(public_path('company_logo/ffsd.png'));
    
    return $invoice->stream();
}
}
