<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_outs', function (Blueprint $table) {
            $table->id();
            $table->string('gi_id')->unique();
            $table->bigInteger('gi_date')->nullable();
            $table->integer('gi_type')->nullable();
            $table->integer('status')->nullable();
            $table->string('gi_status')->nullable();
            $table->integer('gi_year')->nullable();
            $table->text('description')->nullable();
            $table->decimal('final_amount', 15, 2)->default(0);
            $table->string('from_warehouse_id')->nullable();
            $table->string('ivt_id')->comment('UUID id from IVT API');
            $table->string('tran_id')->nullable()->comment('Extracted from description (3rd segment split by _)')->index('IDX_TRAN_ID');
            $table->boolean('has_invoice')->default(0);
            $table->string('vat_invoice_number')->nullable()->comment('Vat invoice number from IVT API')->index('IDX_VAT_INVOICE_NUMBER');
            $table->longText('detail')->nullable()->comment('Reserved for future use');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_outs');
    }
};
