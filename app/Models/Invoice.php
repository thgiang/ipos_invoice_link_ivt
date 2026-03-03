<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'tran_id',
        'vat_invoice_number',
        'tran_id_sync_vat',
        'vat_invoice_date',
        'inv_buyerDisplayName',
        'inv_buyerLegalName',
        'inv_buyerTaxCode',
        'inv_buyerIdentityCard',
        'inv_buyerAddressLine',
        'inv_buyerEmail',
        'sdtnmua',
        'inv_buyerBankName',
        'inv_buyerBankAccount',
        'budget_unit_code',
        'passport_number',
        'note',
        'is_sync_vat',
        'total_amount',
        'discount_amount',
        'enable_vat_cms',
        'enable_vat_pos',
        'vat_amount',
        'list_tran_no',
        'error_message',
        'store_uid',
    ];

    protected $casts = [
        'vat_invoice_date' => 'integer',
        'is_sync_vat' => 'integer',
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'enable_vat_cms' => 'integer',
        'enable_vat_pos' => 'integer',
    ];
}
