<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirror of stock_out_summaries for the inbound side.
     *
     * Write-offs and stocktake adjustments happen in both directions: stock
     * written down one month can be found again the next. Netting the inbound
     * adjustments off the outbound ones is the only way to land on the real
     * consumption figure, so the receipts have to be stored too.
     *
     * The warehouse here is the *receiving* one (to_warehouse), unlike the
     * outbound table where it is the issuing one.
     */
    public function up(): void
    {
        Schema::create('stock_in_summaries', function (Blueprint $table) {
            $table->id();

            $table->date('period_from')->comment('Start of the aggregated period');
            $table->date('period_to')->comment('End of the aggregated period');
            $table->unsignedTinyInteger('gr_type')
                ->comment('1 nhập mua hàng, 2 nhập điều chỉnh, 3 nhập điều chuyển, 4 nhập thành phẩm chế biến, 5 nhập khác');

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

            $table->string('to_warehouse_uid');
            $table->string('to_warehouse_id')->nullable();
            $table->string('to_warehouse_name')->nullable();

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

            // Same reasoning as the outbound table: the report groups by a
            // product_uid it never returns, so no natural key is unique and a
            // period is replaced wholesale on re-crawl.
            $table->index(['period_from', 'period_to', 'gr_type'], 'IDX_IN_PERIOD_GR_TYPE');
            $table->index('item_id', 'IDX_IN_ITEM_ID');
            $table->index('to_warehouse_id', 'IDX_IN_TO_WAREHOUSE_ID');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_in_summaries');
    }
};
