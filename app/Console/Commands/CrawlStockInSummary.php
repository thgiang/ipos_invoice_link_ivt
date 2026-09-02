<?php

namespace App\Console\Commands;

use App\Models\StockInSummary;
use App\Services\IvtClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CrawlStockInSummary extends Command
{
    protected $signature = 'stock-in-summary:crawl
                            {--start_date= : Start date (Y-m-d)}
                            {--end_date= : End date (Y-m-d)}';

    protected $description = 'Crawl the IVT stock-in summary report (Tổng hợp nhập) month by month and save to database';

    /**
     * Receiving warehouses. Same set as the outbound crawl so the two sides can
     * be netted against each other.
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
     * Receipt categories. gr_type 4 mirrors gi_type 6 exactly — what the
     * kitchen consumes leaves as XSD and comes back as finished goods — which
     * is a useful cross-check that the two reports agree.
     */
    private const GR_TYPES = [
        1 => 'Nhập mua hàng',
        2 => 'Nhập điều chỉnh kho (kiểm kê)',
        3 => 'Nhập điều chuyển nội bộ',
        4 => 'Nhập thành phẩm sau chế biến',
        5 => 'Nhập khác',
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

        $this->info("=== Crawling stock-in summary from {$startDate} to {$endDate} ===");
        $this->info('Logging in to IVT...');

        if (! $this->ivt->login()) {
            $this->error('IVT login failed! '.$this->ivt->lastError());

            return self::FAILURE;
        }

        $totalRows = 0;

        foreach ($this->monthlyPeriods($start, $end) as [$periodFrom, $periodTo]) {
            $this->newLine();
            $this->info("--- Period {$periodFrom->toDateString()} .. {$periodTo->toDateString()} ---");

            foreach (self::GR_TYPES as $grType => $label) {
                $rows = $this->fetchPeriod($periodFrom, $periodTo, $grType);

                if ($rows === null) {
                    return self::FAILURE;
                }

                $saved = $this->storePeriod($periodFrom, $periodTo, $grType, $rows);
                $totalRows += $saved;

                $this->line("  [{$grType} — {$label}] {$saved} row(s).");
            }
        }

        $this->newLine();
        $this->info("=== Done! Total stock-in summary rows saved: {$totalRows} ===");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function monthlyPeriods(Carbon $start, Carbon $end): array
    {
        $periods = [];
        $cursor = $start->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $periods[] = [
                $cursor->copy()->max($start)->startOfDay(),
                $cursor->copy()->endOfMonth()->min($end)->endOfDay(),
            ];
            $cursor->addMonth()->startOfMonth();
        }

        return $periods;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchPeriod(Carbon $periodFrom, Carbon $periodTo, int $grType): ?array
    {
        $rows = [];
        $page = 1;
        $totalPages = 1;

        do {
            $response = $this->ivt->stockInSummary(
                fromDate: $periodFrom->getTimestamp(),
                toDate: $periodTo->getTimestamp(),
                toWarehouseUids: self::WAREHOUSE_UIDS,
                page: $page,
                grType: $grType,
                pageSize: self::PAGE_SIZE,
            );

            if (! $response) {
                $this->error("  API error (page {$page}): ".$this->ivt->lastError());

                return null;
            }

            $rows = array_merge($rows, $response['data'] ?? []);
            $totalPages = (int) ($response['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function storePeriod(Carbon $periodFrom, Carbon $periodTo, int $grType, array $rows): int
    {
        $now = now();
        $from = $periodFrom->toDateString();
        $to = $periodTo->toDateString();

        $records = array_map(fn (array $row): array => [
            'period_from' => $from,
            'period_to' => $to,
            'gr_type' => $grType,
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
            'to_warehouse_uid' => $row['to_warehouse_uid'] ?? null,
            'to_warehouse_id' => $row['to_warehouse_id'] ?? null,
            'to_warehouse_name' => $row['to_warehouse_name'] ?? null,
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

        return DB::transaction(function () use ($from, $to, $grType, $records): int {
            StockInSummary::query()
                ->where('period_from', $from)
                ->where('period_to', $to)
                ->where('gr_type', $grType)
                ->delete();

            foreach (array_chunk($records, 500) as $chunk) {
                StockInSummary::insert($chunk);
            }

            return count($records);
        });
    }
}
