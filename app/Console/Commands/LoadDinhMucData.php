<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nạp dữ liệu dựng định mức vào MySQL: đơn hàng Fabi + nguyên liệu phiếu MISA.
 *
 * Hai nguồn đều là file đã kéo về sẵn, nạp lại được bất cứ lúc nào:
 *  - `storage/app/misa/fabi_orders.jsonl`  (crawl_fabi_orders.php + repair_...)
 *  - `storage/app/misa/vouchers.json`      (build_vouchers.php — số SAU chỉnh sửa)
 *
 * Nối hai vế bằng số hóa đơn VAT: Fabi ghi ký hiệu `C26MYA` và số `00002840`,
 * MISA đặt số chứng từ là `C26MYA2840` — bỏ số 0 đệm rồi ghép.
 */
class LoadDinhMucData extends Command
{
    protected $signature = 'app:load-dinh-muc-data
                            {--orders=storage/app/misa/fabi_orders.jsonl}
                            {--vouchers=storage/app/misa/vouchers.json}';

    protected $description = 'Nạp đơn hàng Fabi và nguyên liệu phiếu xuất MISA vào MySQL';

    private const CHUNK = 500;

    public function handle(): int
    {
        $orderPath = base_path((string) $this->option('orders'));
        $voucherPath = base_path((string) $this->option('vouchers'));

        foreach ([$orderPath, $voucherPath] as $path) {
            if (! is_file($path)) {
                $this->error("Khong thay {$path}");

                return self::FAILURE;
            }
        }

        $this->loadOrders($orderPath);
        $this->loadVouchers($voucherPath);
        $this->report();

        return self::SUCCESS;
    }

    private function loadOrders(string $path): void
    {
        DB::table('fabi_order_items')->truncate();
        DB::table('fabi_orders')->truncate();

        $handle = fopen($path, 'r');
        $orders = [];
        $items = [];
        $seen = [];
        $total = 0;

        while (($raw = fgets($handle)) !== false) {
            $row = json_decode($raw, true);
            $tranId = (string) ($row['tran_id'] ?? '');

            // Lượt vá ghi nối vào cùng file nên có thể trùng dòng.
            if ($tranId === '' || isset($seen[$tranId])) {
                continue;
            }

            $seen[$tranId] = true;
            $number = ltrim(trim((string) ($row['so_hd'] ?? '')), '0');
            $series = trim((string) ($row['ky_hieu'] ?? ''));
            $cups = 0.0;

            foreach ($row['mon'] as $item) {
                // Chỉ đồ uống mới tính là "cốc" — luật đóng gói đếm theo cốc.
                if (($item['lop'] ?? '') === 'DU') {
                    $cups += (float) $item['sl'];
                }

                $sizeL = false;

                foreach ($item['tp'] as $topping) {
                    if (mb_strtolower(trim((string) $topping['t'])) === 'size l') {
                        $sizeL = true;
                    }
                }

                $items[] = [
                    'tran_id' => $tranId,
                    'item_id' => (string) ($item['ma'] ?? ''),
                    'item_name' => mb_substr((string) ($item['ten'] ?? ''), 0, 190),
                    'item_class_id' => (string) ($item['lop'] ?? ''),
                    'quantity' => (float) ($item['sl'] ?? 0),
                    'is_size_l' => $sizeL,
                    'toppings' => json_encode($item['tp'], JSON_UNESCAPED_UNICODE),
                ];
            }

            $orders[] = [
                'tran_id' => $tranId,
                'vat_invoice_number' => $number !== '' ? $number : null,
                'vat_invoice_series' => $series !== '' ? $series : null,
                'store_uid' => (string) ($row['kho'] ?? ''),
                'order_type' => (string) ($row['loai'] ?? ''),
                'source_deli' => (string) ($row['nguon'] ?? ''),
                'order_date' => (string) ($row['ngay'] ?? '1970-01-01'),
                'total_amount' => (float) ($row['tien'] ?? 0),
                'item_count' => count($row['mon']),
                'cup_count' => (int) round($cups),
                'misa_refno' => ($series !== '' && $number !== '') ? $series.$number : null,
            ];

            $total++;

            if (count($orders) >= self::CHUNK) {
                DB::table('fabi_orders')->insert($orders);
                $orders = [];
            }

            while (count($items) >= self::CHUNK) {
                DB::table('fabi_order_items')->insert(array_splice($items, 0, self::CHUNK));
            }
        }

        fclose($handle);

        if ($orders !== []) {
            DB::table('fabi_orders')->insert($orders);
        }

        foreach (array_chunk($items, self::CHUNK) as $batch) {
            DB::table('fabi_order_items')->insert($batch);
        }

        $this->line('  Don hang   : '.number_format($total));
    }

    private function loadVouchers(string $path): void
    {
        DB::table('misa_voucher_items')->truncate();

        $vouchers = json_decode((string) file_get_contents($path), true);
        $rows = [];
        $lines = 0;

        foreach ($vouchers as $refno => $voucher) {
            foreach ($voucher['nl'] as $code => $quantity) {
                $rows[] = [
                    'refno' => (string) $refno,
                    'posted_date' => (string) $voucher['ngay'],
                    'item_code' => (string) $code,
                    'quantity' => (float) $quantity,
                ];
                $lines++;

                if (count($rows) >= self::CHUNK) {
                    DB::table('misa_voucher_items')->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('misa_voucher_items')->insert($rows);
        }

        $this->line('  Dong NVL   : '.number_format($lines).' tren '.number_format(count($vouchers)).' phieu');
    }

    private function report(): void
    {
        $orders = DB::table('fabi_orders')->count();
        $withInvoice = DB::table('fabi_orders')->whereNotNull('misa_refno')->count();
        $matched = DB::table('fabi_orders')
            ->whereNotNull('misa_refno')
            ->whereIn('misa_refno', fn ($q) => $q->from('misa_voucher_items')->select('refno')->distinct())
            ->count();

        $this->newLine();
        $this->line('  Tong don             : '.number_format($orders));
        $this->line('  Co so hoa don VAT    : '.number_format($withInvoice));
        $this->line('  Noi duoc voi phieu   : '.number_format($matched)
            .($withInvoice ? sprintf(' (%.1f%%)', 100 * $matched / $withInvoice) : ''));

        $this->newLine();
        $this->line('  Don theo thang:');

        foreach (DB::table('fabi_orders')
            ->selectRaw('MONTH(order_date) as thang, COUNT(*) as n')
            ->groupBy('thang')->orderBy('thang')->get() as $row) {
            $this->line(sprintf('    thang %d: %s', $row->thang, number_format($row->n)));
        }
    }
}
