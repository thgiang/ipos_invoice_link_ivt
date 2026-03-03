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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('tran_id')->unique();
            $table->string('vat_invoice_number')->nullable();
            $table->string('tran_id_sync_vat')->nullable();
            $table->bigInteger('vat_invoice_date')->nullable();
            $table->string('inv_buyerDisplayName')->nullable();
            $table->string('inv_buyerLegalName')->nullable();
            $table->string('inv_buyerTaxCode')->nullable();
            $table->string('inv_buyerIdentityCard')->nullable();
            $table->text('inv_buyerAddressLine')->nullable();
            $table->string('inv_buyerEmail')->nullable();
            $table->string('sdtnmua')->nullable();
            $table->string('inv_buyerBankName')->nullable();
            $table->string('inv_buyerBankAccount')->nullable();
            $table->string('budget_unit_code')->nullable();
            $table->string('passport_number')->nullable();
            $table->text('note')->nullable();
            $table->tinyInteger('is_sync_vat')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->tinyInteger('enable_vat_cms')->default(0);
            $table->tinyInteger('enable_vat_pos')->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->string('list_tran_no')->nullable();
            $table->text('error_message')->nullable();
            $table->string('store_uid');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
