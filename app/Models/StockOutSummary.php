<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOutSummary extends Model
{
    protected $fillable = [
        'period_from',
        'period_to',
        'gi_type',
        'item_uid',
        'item_id',
        'item_name',
        'item_class_id',
        'item_class_name',
        'main_unit_id',
        'main_unit_name',
        'second_unit_id',
        'second_unit_name',
        'lot_no',
        'lot_date',
        'from_warehouse_uid',
        'from_warehouse_id',
        'from_warehouse_name',
        'main_unit_qty',
        'second_unit_qty',
        'product_qty',
        'product_second_unit_qty',
        'main_unit_price',
        'price_cost',
        'amount_vat',
        'discount_amount',
        'sub_total',
        'amount_org',
        'amount',
        'amount_cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'gi_type' => 'integer',
            'main_unit_qty' => 'decimal:6',
            'second_unit_qty' => 'decimal:6',
            'product_qty' => 'decimal:6',
            'product_second_unit_qty' => 'decimal:6',
            'main_unit_price' => 'decimal:2',
            'price_cost' => 'decimal:2',
            'amount_vat' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'sub_total' => 'decimal:2',
            'amount_org' => 'decimal:2',
            'amount' => 'decimal:2',
            'amount_cost' => 'decimal:2',
        ];
    }
}
