<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Without a gi_type dimension the summary lumps internal transfers in with
     * real consumption, so goods the kitchen ships to a shop are counted once
     * on the transfer and again on the sale. Splitting the crawl by gi_type
     * lets the double counting be filtered out.
     *
     * Existing rows hold the un-split total, so they are cleared: the crawler
     * must be re-run to repopulate per gi_type.
     */
    public function up(): void
    {
        Schema::table('stock_out_summaries', function (Blueprint $table) {
            $table->unsignedTinyInteger('gi_type')
                ->default(0)
                ->after('period_to')
                ->comment('1 XBH bán hàng, 2 XDC điều chỉnh, 3 XNB điều chuyển, 4 XH hủy, 5 XK NV dùng, 6 XSD Bếp chế biến');

            $table->index(['period_from', 'period_to', 'gi_type'], 'IDX_PERIOD_GI_TYPE');
        });

        Schema::table('stock_out_summaries', function (Blueprint $table) {
            $table->dropIndex('IDX_PERIOD');
        });

        DB::table('stock_out_summaries')->truncate();
    }

    public function down(): void
    {
        Schema::table('stock_out_summaries', function (Blueprint $table) {
            $table->index(['period_from', 'period_to'], 'IDX_PERIOD');
            $table->dropIndex('IDX_PERIOD_GI_TYPE');
            $table->dropColumn('gi_type');
        });
    }
};
