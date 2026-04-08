<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_outs', function (Blueprint $table) {
            $table->string('loai_xuat')->nullable()->after('gi_type')
                ->comment('XUAT_BAN_HANG (gi_type=1), XUAT_HUY (gi_type=4), XUAT_NV_DUNG (gi_type=5)');
        });
    }

    public function down(): void
    {
        Schema::table('stock_outs', function (Blueprint $table) {
            $table->dropColumn('loai_xuat');
        });
    }
};
