<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\StockOut;
use App\Services\IvtClient;
use Illuminate\Console\Command;

class CrawlStockOuts extends Command
{
    protected $signature = 'stock-outs:crawl
                            {--start_date= : Start date (Y-m-d)}
                            {--end_date= : End date (Y-m-d)}';

    protected $description = 'Crawl stock-out records from IVT API and save to database';

    private const GI_TYPE_MAP = [
        1 => 'XUAT_BAN_HANG',
        4 => 'XUAT_HUY',
        5 => 'XUAT_NV_DUNG',
    ];

    /**
     * Store prefixes stripped off the description when extracting a tran_id.
     * A newly opened store must be added here or its tran_id will be wrong.
     */
    private const STORE_PREFIXES = [
        'FB_CHLANGHA_',
        'FB_CHLETRONGTAN_',
        'FB_CHKHUCTHUADU_',
        'FB_CHGIANGVANMINH_',
    ];

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

        // IVT API uses seconds timestamps
        $startTimestamp = strtotime($startDate.' 00:00:00');
        $endTimestamp = strtotime($endDate.' 23:59:59');

        $this->info("=== Crawling stock-outs from {$startDate} to {$endDate} ===");

        $this->info('Logging in to IVT...');

        if (! $this->ivt->login()) {
            $this->error('IVT login failed! '.$this->ivt->lastError());

            return self::FAILURE;
        }

        $this->info('Logged in successfully. Token obtained.');

        $totalProcessed = 0;

        foreach (self::GI_TYPE_MAP as $giType => $loaiXuat) {
            $this->newLine();
            $this->info("--- gi_type={$giType} ({$loaiXuat}) ---");

            $processed = $this->crawlByGiType(
                fromDate: $startTimestamp,
                toDate: $endTimestamp,
                giType: $giType,
                loaiXuat: $loaiXuat,
            );

            if ($processed === null) {
                return self::FAILURE;
            }

            $totalProcessed += $processed;
        }

        $this->newLine();
        $this->info("=== Done! Total stock-out records processed: {$totalProcessed} ===");

        return self::SUCCESS;
    }

    /**
     * Crawl all pages for a single gi_type. Returns total records processed, or null on fatal error.
     */
    private function crawlByGiType(
        int $fromDate,
        int $toDate,
        int $giType,
        string $loaiXuat,
    ): ?int {
        $page = 1;
        $totalPages = 1;
        $processed = 0;

        do {
            $response = $this->ivt->stockOuts(
                fromDate: $fromDate,
                toDate: $toDate,
                giType: $giType,
                page: $page,
            );

            if (! $response) {
                $this->error("API error (page {$page}): ".$this->ivt->lastError());
                $this->warn("  Failed to fetch page {$page}. Stopping for gi_type={$giType}.");

                return null;
            }

            $totalPages = $response['total_pages'] ?? 1;
            $items = $response['data'] ?? [];

            foreach ($items as $item) {
                $tranId = $this->extractTranId($item['description'] ?? '');

                if (! $tranId) {
                    $this->warn("  Failed to extract tran_id from description: {$item['description']} of {$item['id']}");
                }

                $sale = Sale::where('tran_id', $tranId)->first();
                $hasSale = $sale ? 1 : 0;
                $vatInvoiceNumber = $sale?->vat_invoice_number;

                StockOut::updateOrCreate(
                    ['gi_id' => $item['gi_id']],
                    [
                        'gi_date' => $item['gi_date'] ?? null,
                        'gi_type' => $item['gi_type'] ?? null,
                        'loai_xuat' => $loaiXuat,
                        'status' => $item['status'] ?? null,
                        'gi_status' => $item['gi_status'] ?? null,
                        'gi_year' => $item['gi_year'] ?? null,
                        'description' => $item['description'] ?? null,
                        'final_amount' => $item['final_amount'] ?? 0,
                        'from_warehouse_id' => $item['from_warehouse_id'] ?? null,
                        'ivt_id' => $item['id'],
                        'tran_id' => $tranId,
                        'has_sale' => $hasSale,
                        'vat_invoice_number' => $vatInvoiceNumber,
                    ],
                );
                $processed++;
            }

            $this->line("  Page {$page}/{$totalPages} — ".count($items).' record(s) processed.');
            $page++;
        } while ($page <= $totalPages);

        return $processed;
    }

    /**
     * Extract tran_id from description by taking the segment before '__' and
     * stripping the store prefix.
     * Example: "FB_CHGIANGVANMINH_59BVR9MZ1ZJ5GS26ISMTCEKA__58000" => "59BVR9MZ1ZJ5GS26ISMTCEKA"
     */
    private function extractTranId(?string $description): ?string
    {
        if (! $description) {
            return null;
        }

        $parts = explode('__', $description);

        return str_replace(self::STORE_PREFIXES, '', $parts[0]);
    }
}
