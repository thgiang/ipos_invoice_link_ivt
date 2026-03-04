<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOut extends Model
{
    protected $fillable = [
        'gi_id',
        'gi_date',
        'gi_type',
        'status',
        'gi_status',
        'gi_year',
        'description',
        'final_amount',
        'from_warehouse_id',
        'ivt_id',
        'tran_id',
        'has_invoice',
        'detail',
    ];

    protected $casts = [
        'gi_date'      => 'integer',
        'gi_type'      => 'integer',
        'status'       => 'integer',
        'gi_year'      => 'integer',
        'final_amount' => 'decimal:2',
    ];
}
