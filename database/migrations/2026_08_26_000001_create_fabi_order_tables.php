<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đơn hàng Fabi kèm dòng món, và nguyên liệu của phiếu xuất MISA tương ứng.
 *
 * Dựng để xây định mức tiêu hao thực tế: `fabi_order_items` là vế "bán cái gì",
 * `misa_voucher_items` là vế "xuất cái gì", nối nhau qua số hóa đơn VAT.
 *
 * Chi tiết đơn Fabi chỉ lấy được bằng endpoint `get-sale-by-list-tran-id` và
 * không rõ còn đọc được bao lâu (bản số ít đã 404 với đơn cũ hơn hai tháng),
 * nên hạ về đây để lần sau không phải kéo lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fabi_orders', function (Blueprint $table): void {
            $table->string('tran_id', 64)->primary();
            $table->string('vat_invoice_number', 20)->nullable()->index();
            $table->string('vat_invoice_series', 20)->nullable();
            $table->string('store_uid', 40)->index();
            $table->string('order_type', 20)->nullable();
            // NHANVIENDUNG / HUYDO thi khong xuat bao bi — phai loc khi do dinh muc dong goi.
            $table->string('source_deli', 40)->nullable()->index();
            $table->date('order_date')->index();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->unsignedSmallInteger('item_count')->default(0);
            $table->unsignedSmallInteger('cup_count')->default(0);
            $table->string('misa_refno', 30)->nullable()->index();
        });

        Schema::create('fabi_order_items', function (Blueprint $table): void {
            $table->id();
            $table->string('tran_id', 64)->index();
            $table->string('item_id', 40)->nullable()->index();
            $table->string('item_name', 190)->index();
            $table->string('item_class_id', 20)->nullable();
            $table->decimal('quantity', 10, 3)->default(0);
            $table->boolean('is_size_l')->default(false);
            // Giu nguyen mang topping: size, coc dac biet va topping an tien deu nam trong day.
            $table->json('toppings')->nullable();
        });

        Schema::create('misa_voucher_items', function (Blueprint $table): void {
            $table->id();
            $table->string('refno', 30)->index();
            $table->date('posted_date')->index();
            $table->string('item_code', 40)->index();
            // So SAU chinh sua — khach chot coi ban da sua la dung.
            $table->decimal('quantity', 14, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('misa_voucher_items');
        Schema::dropIfExists('fabi_order_items');
        Schema::dropIfExists('fabi_orders');
    }
};
