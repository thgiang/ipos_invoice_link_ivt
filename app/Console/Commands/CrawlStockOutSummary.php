<?php

namespace App\Console\Commands;

use App\Models\StockOutSummary;
use App\Services\IvtClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CrawlStockOutSummary extends Command
{
    protected $signature = 'stock-out-summary:crawl
                            {--start_date= : Start date (Y-m-d)}
                            {--end_date= : End date (Y-m-d)}';

    protected $description = 'Crawl the IVT stock-out summary report (Tổng hợp xuất) month by month and save to database';

    /**
     * Warehouses the report is aggregated over. Leaving the filter empty makes
     * the IVT report hang, so the set has to be spelled out. A new warehouse
     * must be added here or its stock-outs will be missing from the summary.
     */
    private const WAREHOUSE_UIDS = [
        'c8071026-02ac-48b2-bc72-b5039941beee', // KTHAIHA — Kho Thái Hà
        '57fa2e6e-5f0c-4ae7-ada1-eba0c01d8235', // CHLETRONGTAN — CS 2 Lê Trọng Tấn
        '3e187ae6-0dfa-4173-8bc5-730dbdc6a03d', // CHLANGHA — CS 1 Láng Hạ
        '11a2b533-5bd4-4b17-842f-3862842338fd', // CHKHUCTHUADU — CS 3 Khúc Thừa Dụ
        '4d168416-2726-42ad-9772-ff545e2cfae5', // CHGIANGVANMINH — CS 5 Giang Văn Minh
        'eca32d75-6c12-437b-ab2d-2137af476f85', // BTRUNGLIET — Bếp tổng Trung Liệt
    ];

    /**
     * Stock-out categories, identified by the gi_id prefix IVT gives each one.
     * They are crawled separately so that internal transfers (XNB) can be told
     * apart from real consumption — summing every type reproduces the
     * unfiltered report exactly.
     */
    private const GI_TYPES = [
        1 => 'XBH — Xuất bán hàng',
        2 => 'XDC — Xuất điều chỉnh kho (kiểm kê)',
        3 => 'XNB — Xuất điều chuyển nội bộ',
        4 => 'XH — Xuất hủy',
        5 => 'XK — Xuất nhân viên dùng',
        6 => 'XSD — Xuất sử dụng (Bếp chế biến)',
    ];

    private const PAGE_SIZE = 500;

    public function __construct(private readonly IvtClient $ivt)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $startDate = $this->option('start_date');
        $endDate = $this->option('end_date');

        if (! $startDate || ! $endDate) {
            $this->error('Both --start_date and --end_date are required (format: Y-m-d)');

            return self::FAILURE;
        }

        try {
            $start = Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
            $end = Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
        } catch (\Throwable) {
            $this->error('Invalid date format. Please use Y-m-d.');

            return self::FAILURE;
        }

        if ($start->greaterThan($end)) {
            $this->error('--start_date must not be after --end_date.');

            return self::FAILURE;
        }

        $periods = $this->monthlyPeriods($start, $end);

        $this->info("=== Crawling stock-out summary from {$startDate} to {$endDate} ===");
        $this->line('Split into '.count($periods).' monthly period(s) — the report times out server-side on wider windows.');

        $this->info('Logging in to IVT...');

        if (! $this->ivt->login()) {
            $this->error('IVT login failed! '.$this->ivt->lastError());

            return self::FAILURE;
        }

        $this->info('Logged in successfully.');

        $totalRows = 0;

        foreach ($periods as [$periodFrom, $periodTo]) {
            $this->newLine();
            $this->info("--- Period {$periodFrom->toDateString()} .. {$periodTo->toDateString()} ---");

            foreach (self::GI_TYPES as $giType => $label) {
                $rows = $this->fetchPeriod($periodFrom, $periodTo, $giType);

                if ($rows === null) {
                    return self::FAILURE;
                }

                $saved = $this->storePeriod($periodFrom, $periodTo, $giType, $rows);
                $totalRows += $saved;

                $this->line("  [{$label}] {$saved} row(s).");
            }
        }

        $this->newLine();
        $this->info("=== Done! Total summary rows saved: {$totalRows} ===");

        return self::SUCCESS;
    }

    /**
     * Split [$start, $end] into calendar months clipped to the requested range.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function monthlyPeriods(Carbon $start, Carbon $end): array
    {
        $periods = [];
        $cursor = $start->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $periodFrom = $cursor->copy()->max($start)->startOfDay();
            $periodTo = $cursor->copy()->endOfMonth()->min($end)->endOfDay();

            $periods[] = [$periodFrom, $periodTo];
            $cursor->addMonth()->startOfMonth();
        }

        return $periods;
    }

    /**
     * Page through the report for one period. Returns null on a fatal API error.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchPeriod(Carbon $periodFrom, Carbon $periodTo, int $giType): ?array
    {
        $rows = [];
        $page = 1;
        $totalPages = 1;

        do {
            $response = $this->ivt->stockOutSummary(
                fromDate: $periodFrom->getTimestamp(),
                toDate: $periodTo->getTimestamp(),
                fromWarehouseUids: self::WAREHOUSE_UIDS,
                giType: $giType,
                page: $page,
                pageSize: self::PAGE_SIZE,
            );

            if (! $response) {
                $this->error("  API error (page {$page}): ".$this->ivt->lastError());

                return null;
            }

            $data = $response['data'] ?? [];
            $rows = array_merge($rows, $data);
            $totalPages = (int) ($response['total_pages'] ?? 1);

            if ($totalPages > 1) {
                $this->line("    page {$page}/{$totalPages} — ".count($data).' row(s)');
            }
            $page++;
        } while ($page <= $totalPages);

        return $rows;
    }

    /**
     * Replace every stored row for this period with the freshly fetched set.
     *
     * The report groups by a product_uid it never returns, so one
     * (period, item, warehouse) can yield several rows that differ only by
     * item_name. That rules out updateOrCreate on a natural key — a wholesale
     * replace is both correct and idempotent.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function storePeriod(Carbon $periodFrom, Carbon $periodTo, int $giType, array $rows): int
    {
        $now = now();
        $from = $periodFrom->toDateString();
        $to = $periodTo->toDateString();

        $records = array_map(fn (array $row): array => [
            'period_from' => $from,
            'period_to' => $to,
            'gi_type' => $giType,
            'item_uid' => $row['item_uid'] ?? null,
            'item_id' => $row['item_id'] ?? null,
            'item_name' => $row['item_name'] ?? null,
            'item_class_id' => $row['item_class_id'] ?? null,
            'item_class_name' => $row['item_class_name'] ?? null,
            'main_unit_id' => $row['main_unit_id'] ?? null,
            'main_unit_name' => $row['main_unit_name'] ?? null,
            'second_unit_id' => $row['second_unit_id'] ?? null,
            'second_unit_name' => $row['second_unit_name'] ?? null,
            'lot_no' => $row['lot_no'] ?? null,
            'lot_date' => $row['lot_date'] ?? null,
            'from_warehouse_uid' => $row['from_warehouse_uid'] ?? null,
            'from_warehouse_id' => $row['from_warehouse_id'] ?? null,
            'from_warehouse_name' => $row['from_warehouse_name'] ?? null,
            'main_unit_qty' => $row['main_unit_qty'] ?? 0,
            'second_unit_qty' => $row['second_unit_qty'] ?? 0,
            'product_qty' => $row['product_qty'] ?? 0,
            'product_second_unit_qty' => $row['product_second_unit_qty'] ?? 0,
            'main_unit_price' => $row['main_unit_price'] ?? 0,
            'price_cost' => $row['price_cost'] ?? 0,
            'amount_vat' => $row['amount_vat'] ?? 0,
            'discount_amount' => $row['discount_amount'] ?? 0,
            'sub_total' => $row['sub_total'] ?? 0,
            'amount_org' => $row['amount_org'] ?? 0,
            'amount' => $row['amount'] ?? 0,
            'amount_cost' => $row['amount_cost'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        return DB::transaction(function () use ($from, $to, $giType, $records): int {
            StockOutSummary::query()
                ->where('period_from', $from)
                ->where('period_to', $to)
                ->where('gi_type', $giType)
                ->delete();

            foreach (array_chunk($records, 500) as $chunk) {
                StockOutSummary::insert($chunk);
            }

            return count($records);
        });
    }
}
