<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The IVT "Tổng hợp xuất" report aggregates quantities over a whole date
     * range, so a row carries no date of its own — only the period it was
     * summed over. period_from/period_to record that period, which is why the
     * same item can legitimately appear once per period.
     */
    public function up(): void
    {
        Schema::create('stock_out_summaries', function (Blueprint $table) {
            $table->id();

            $table->date('period_from')->comment('Start of the aggregated period');
            $table->date('period_to')->comment('End of the aggregated period');

            $table->string('item_uid')->comment('UUID id from IVT API');
            $table->string('item_id');
            $table->string('item_name');
            $table->string('item_class_id')->nullable();
            $table->string('item_class_name')->nullable();

            $table->string('main_unit_id')->nullable();
            $table->string('main_unit_name')->nullable();
            $table->string('second_unit_id')->nullable();
            $table->string('second_unit_name')->nullable();

            $table->string('lot_no')->nullable();
            $table->string('lot_date')->nullable();

            $table->string('from_warehouse_uid');
            $table->string('from_warehouse_id')->nullable();
            $table->string('from_warehouse_name')->nullable();

            $table->decimal('main_unit_qty', 20, 6)->default(0);
            $table->decimal('second_unit_qty', 20, 6)->default(0);
            $table->decimal('product_qty', 20, 6)->default(0);
            $table->decimal('product_second_unit_qty', 20, 6)->default(0);

            $table->decimal('main_unit_price', 18, 2)->default(0);
            $table->decimal('price_cost', 18, 2)->default(0);
            $table->decimal('amount_vat', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('sub_total', 18, 2)->default(0);
            $table->decimal('amount_org', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('amount_cost', 18, 2)->default(0);

            $table->timestamps();

            // No unique key on the natural columns: the report groups by a
            // product_uid it never returns, so one (period, item, warehouse)
            // can legitimately yield several rows that differ only by
            // item_name. Re-crawling replaces a period wholesale instead.
            $table->index(['period_from', 'period_to'], 'IDX_PERIOD');
            $table->index('item_id', 'IDX_ITEM_ID');
            $table->index('from_warehouse_id', 'IDX_FROM_WAREHOUSE_ID');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_out_summaries');
    }
};
