<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'sale_id',
        'tran_id',
        'tran_no',
        'tran_date',
        'vat_invoice_number',
        'vat_invoice_date',
        'vat_invoice_series',
        'is_sync_vat',
        'total_amount',
        'store_uid',
        'brand_uid',
        'payment_method_id',
    ];

    protected $casts = [
        'tran_date'         => 'integer',
        'vat_invoice_date'  => 'integer',
        'is_sync_vat'       => 'integer',
        'total_amount'      => 'decimal:2',
    ];
}
