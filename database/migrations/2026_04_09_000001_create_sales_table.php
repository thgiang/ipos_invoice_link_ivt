<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_id')->unique()->comment('UUID id from Fabi API');
            $table->string('tran_id')->unique()->comment('Transaction ID (primary business key)');
            $table->string('tran_no')->nullable()->comment('Transaction number');
            $table->bigInteger('tran_date')->nullable()->comment('Transaction date in milliseconds');
            $table->string('vat_invoice_number')->nullable()->index();
            $table->bigInteger('vat_invoice_date')->nullable()->comment('VAT invoice date in milliseconds');
            $table->string('vat_invoice_series')->nullable()->comment('VAT invoice series (ky hieu)');
            $table->tinyInteger('is_sync_vat')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('store_uid')->index();
            $table->string('brand_uid')->nullable();
            $table->string('payment_method_id')->nullable()->comment('Primary payment method ID');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
