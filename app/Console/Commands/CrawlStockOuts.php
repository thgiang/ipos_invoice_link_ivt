<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\StockOut;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CrawlStockOuts extends Command
{
    protected $signature = 'stock-outs:crawl
                            {--start_date= : Start date (Y-m-d)}
                            {--end_date= : End date (Y-m-d)}';

    protected $description = 'Crawl stock-out records from IVT API and save to database';

    private string $baseUrl = 'https://apiivt.ipos.vn';

    private const GI_TYPE_MAP = [
        1 => 'XUAT_BAN_HANG',
        4 => 'XUAT_HUY',
        5 => 'XUAT_NV_DUNG',
    ];

    public function handle(): int
    {
        $startDate = $this->option('start_date');
        $endDate = $this->option('end_date');

        if (! $startDate || ! $endDate) {
            $this->error('Both --start_date and --end_date are required (format: Y-m-d)');
            return self::FAILURE;
        }

        // IVT API uses seconds timestamps
        $startTimestamp = strtotime($startDate . ' 00:00:00');
        $endTimestamp   = strtotime($endDate . ' 23:59:59');

        $this->info("=== Crawling stock-outs from {$startDate} to {$endDate} ===");

        $this->info('Logging in to IVT...');
        $userToken = $this->loginIvt();

        if (! $userToken) {
            $this->error('IVT login failed!');
            return self::FAILURE;
        }

        $this->info('Logged in successfully. Token obtained.');

        $totalProcessed = 0;

        foreach (self::GI_TYPE_MAP as $giType => $loaiXuat) {
//            if ($giType == 1) {
//                continue;
//            }

            $this->newLine();
            $this->info("--- gi_type={$giType} ({$loaiXuat}) ---");

            $processed = $this->crawlByGiType(
                userToken:  $userToken,
                fromDate:   $startTimestamp,
                toDate:     $endTimestamp,
                giType:     $giType,
                loaiXuat:   $loaiXuat,
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
        string $userToken,
        int $fromDate,
        int $toDate,
        int $giType,
        string $loaiXuat,
    ): ?int {
        $page       = 1;
        $totalPages = 1;
        $processed  = 0;

        do {
            $response = $this->getStockOuts(
                userToken: $userToken,
                fromDate:  $fromDate,
                toDate:    $toDate,
                giType:    $giType,
                page:      $page,
            );

            if (! $response) {
                $this->warn("  Failed to fetch page {$page}. Stopping for gi_type={$giType}.");
                return null;
            }

            $totalPages = $response['total_pages'] ?? 1;
            $items      = $response['data'] ?? [];

            foreach ($items as $item) {
                $tranId = $this->extractTranId($item['description'] ?? '');

                if (! $tranId) {
                    $this->warn("  Failed to extract tran_id from description: {$item['description']} of {$item['id']}");
                    //continue;
                }

                $sale               = Sale::where('tran_id', $tranId)->first();
                $hasSale            = $sale ? 1 : 0;
                $vatInvoiceNumber   = $sale?->vat_invoice_number;

                StockOut::updateOrCreate(
                    ['gi_id' => $item['gi_id']],
                    [
                        'gi_date'            => $item['gi_date'] ?? null,
                        'gi_type'            => $item['gi_type'] ?? null,
                        'loai_xuat'          => $loaiXuat,
                        'status'             => $item['status'] ?? null,
                        'gi_status'          => $item['gi_status'] ?? null,
                        'gi_year'            => $item['gi_year'] ?? null,
                        'description'        => $item['description'] ?? null,
                        'final_amount'       => $item['final_amount'] ?? 0,
                        'from_warehouse_id'  => $item['from_warehouse_id'] ?? null,
                        'ivt_id'             => $item['id'],
                        'tran_id'            => $tranId,
                        'has_sale'           => $hasSale,
                        'vat_invoice_number' => $vatInvoiceNumber,
                    ],
                );
                $processed++;
            }

            $this->line("  Page {$page}/{$totalPages} — " . count($items) . " record(s) processed.");
            $page++;
        } while ($page <= $totalPages);

        return $processed;
    }

    /**
     * Extract tran_id from description by splitting on '_' and taking the 3rd segment.
     * Example: "FB_CHGIANGVANMINH_59BVR9MZ1ZJ5GS26ISMTCEKA__58000" => "59BVR9MZ1ZJ5GS26ISMTCEKA"
     */
    private function extractTranId(?string $description): ?string
    {
        if (! $description) {
            return null;
        }

        $parts = explode('__', $description);

        $tranId = $parts[0];

        // Remove _EDT_
        $tranId = str_replace('FB_CHLANGHA_', '', $tranId);
        $tranId = str_replace('FB_CHLETRONGTAN_', '', $tranId);
        $tranId = str_replace('FB_CHKHUCTHUADU_', '', $tranId);
        $tranId = str_replace('FB_CHGIANGVANMINH_', '', $tranId);

        return $tranId;
    }

    private function loginIvt(): ?string
    {
        $response = Http::withHeaders([
            'Content-Type'               => 'application/json',
            'Accept'                     => 'application/json, text/plain, */*',
            'language'                   => 'vi',
            'device-type'                => 'WEB',
            'x-timezone'                 => '7',
            'access-token'               => env('IVT_ACCESS_TOKEN', 'QEEWXV5B27K8YRG8XXZ83KA6LZQAZY6DRD91'),
            'device-id'                  => env('IVT_DEVICE_ID', '8ee500c7-e317-4407-b5b7-094ac9a03896'),
            'device-app-version'         => '2.14.3',
            'device-os-platform'         => 'Microsoft Windows',
            'device-os-browser'          => 'Chrome',
            'device-os-version'          => '145.0.0.0',
            'device-os-name'             => 'Windows 10.0',
            'secret-key'                 => '713528fac3057e1a7945806cbb366183',
            'Referer'                    => 'https://ivt.ipos.vn/',
            'Access-Control-Allow-Origin' => 'https://apiivt.ipos.vn',
        ])->post("{$this->baseUrl}/api/main/v1/auth/login", [
            'user_email' => env('IVT_EMAIL'),
            'password'   => env('IVT_PASSWORD'),
        ]);

        if ($response->successful()) {
            return $response->json('data.user_token');
        }

        $this->error('IVT Login response: ' . $response->body());
        return null;
    }

    private function getStockOuts(
        string $userToken,
        int $fromDate,
        int $toDate,
        int $giType,
        int $page,
        int $pageSize = 100,
    ): ?array {
        $response = Http::withHeaders([
            'Accept'                      => 'application/json, text/plain, */*',
            'language'                    => 'vi',
            'device-type'                 => 'WEB',
            'x-timezone'                  => '7',
            'access-token'                => env('IVT_ACCESS_TOKEN', 'QEEWXV5B27K8YRG8XXZ83KA6LZQAZY6DRD91'),
            'device-id'                   => env('IVT_DEVICE_ID', '8ee500c7-e317-4407-b5b7-094ac9a03896'),
            'device-app-version'          => '2.14.3',
            'device-os-platform'          => 'Microsoft Windows',
            'device-os-browser'           => 'Chrome',
            'device-os-version'           => '145.0.0.0',
            'device-os-name'              => 'Windows 10.0',
            'secret-key'                  => '713528fac3057e1a7945806cbb366183',
            'user-token'                  => $userToken,
            'Referer'                     => 'https://ivt.ipos.vn/',
            'Access-Control-Allow-Origin' => 'https://apiivt.ipos.vn',
        ])->get("{$this->baseUrl}/api/main/v3/service/stock-out", [
            'from_date'          => $fromDate,
            'to_date'            => $toDate,
            'gi_type'            => $giType,
            'search'             => '',
            'financial_paper_id' => '',
            'from_warehouse_uid' => '',
            'customer_uid'       => '',
            'gi_status'          => 'COMPLETED',
            'page_size'          => $pageSize,
            'page'               => $page,
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        $this->error("API error (page {$page}): " . $response->body());
        return null;
    }
}
