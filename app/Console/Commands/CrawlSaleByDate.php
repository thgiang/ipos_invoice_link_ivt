<?php

namespace App\Console\Commands;

use App\Models\Sale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CrawlSaleByDate extends Command
{
    protected $signature = 'sales:crawl
                            {--start_date= : Start date (Y-m-d)}
                            {--end_date= : End date (Y-m-d)}';

    protected $description = 'Crawl all sales from Fabi sale-by-date API and save to database';

    private string $baseUrl = 'https://posapi.ipos.vn';

    public function handle(): int
    {
        $startDate = $this->option('start_date');
        $endDate   = $this->option('end_date');

        if (! $startDate || ! $endDate) {
            $this->error('Both --start_date and --end_date are required (format: Y-m-d)');
            return self::FAILURE;
        }

        // Fabi API uses millisecond timestamps
        $startTimestamp = strtotime($startDate) * 1000;
        $endTimestamp   = (strtotime($endDate . ' 23:59:59') * 1000) + 999;

        $this->info("=== Crawling sales from {$startDate} to {$endDate} ===");

        // Step 1: Login
        $this->info('Logging in to Fabi...');
        $loginData = $this->login();

        if (! $loginData) {
            $this->error('Login failed!');
            return self::FAILURE;
        }

        $token      = $loginData['token'];
        $companyUid = $loginData['company']['id'];
        $brandUid   = $loginData['brands'][0]['id'];
        $stores     = collect($loginData['stores'])->where('active', 1);

        $this->info("Logged in successfully.");
        $this->info("Company UID: {$companyUid}");
        $this->info("Brand UID: {$brandUid}");
        $this->info("Found {$stores->count()} active store(s).");

        $totalInserted = 0;

        // Step 2: Crawl sales for each store, page by page
        foreach ($stores as $store) {
            $storeUid  = $store['id'];
            $storeName = $store['store_name'];
            $this->newLine();
            $this->info("--- Store: {$storeName} ({$storeUid}) ---");

            $page = 1;

            do {
                $response = $this->getSaleByDate(
                    token:      $token,
                    companyUid: $companyUid,
                    brandUid:   $brandUid,
                    storeUid:   $storeUid,
                    startDate:  $startTimestamp,
                    endDate:    $endTimestamp,
                    page:       $page,
                );

                if (! $response) {
                    $this->warn("  Failed to fetch page {$page}. Stopping for this store.");
                    break;
                }

                $sales = $response['data'] ?? [];

                if (empty($sales)) {
                    $this->line("  Page {$page} — no more records.");
                    break;
                }

                foreach ($sales as $saleData) {
                    $paymentMethods  = $saleData['payment_method'] ?? [];
                    $paymentMethodId = $paymentMethods[0]['payment_method_id'] ?? null;

                    $created_at          = date('Y-m-d H:i:s', ($saleData['tran_date'] ?? 0) / 1000);
                    $updated_at          = date('Y-m-d H:i:s', ($saleData['tran_date'] ?? 0) / 1000);
                    Sale::updateOrCreate(
                        ['tran_id' => $saleData['tran_id']],
                        [
                            'sale_id'             => $saleData['id'],
                            'tran_no'             => $saleData['tran_no'] ?? null,
                            'tran_date'           => $saleData['tran_date'] ?? null,
                            'vat_invoice_number'  => $saleData['vat_invoice_number'] ?? null,
                            'vat_invoice_date'    => $saleData['vat_invoice_date'] ?? null,
                            'vat_invoice_series'  => $saleData['vat_invoice_series'] ?? null,
                            'is_sync_vat'         => $saleData['is_sync_vat'] ?? 0,
                            'total_amount'        => $saleData['total_amount'] ?? 0,
                            'store_uid'           => $storeUid,
                            'brand_uid'           => $saleData['brand_uid'] ?? null,
                            'payment_method_id'   => $paymentMethodId,
                            'created_at'          => $created_at,
                            'updated_at'          => $updated_at,
                        ],
                    );
                    $totalInserted++;
                }

                $this->line("  Page {$page} — " . count($sales) . " sale(s) processed.");
                $page++;

            } while (true);
        }

        $this->newLine();
        $this->info("=== Done! Total sales processed: {$totalInserted} ===");

        return self::SUCCESS;
    }

    private function login(): ?array
    {
        $response = Http::withHeaders([
            'Content-Type'      => 'application/json;charset=UTF-8',
            'Accept'            => 'application/json, text/plain, */*',
            'Origin'            => 'https://fabi.ipos.vn',
            'Referer'           => 'https://fabi.ipos.vn/',
            'accept-language'   => 'vi',
            'access_token'      => '5c885b2ef8c34fb7b1d1fad11eef7bec',
            'fabi_type'         => 'pos-cms',
            'x-client-timezone' => '25200000',
        ])->post("{$this->baseUrl}/api/accounts/v1/user/login", [
            'email'    => env('FABI_EMAIL'),
            'password' => env('FABI_PASSWORD'),
        ]);

        if ($response->successful()) {
            return $response->json('data');
        }

        $this->error('Login response: ' . $response->body());
        return null;
    }

    private function getSaleByDate(
        string $token,
        string $companyUid,
        string $brandUid,
        string $storeUid,
        int $startDate,
        int $endDate,
        int $page,
        int $pageSize = 50,
    ): ?array {
        $response = Http::withHeaders([
            'Authorization'     => $token,
            'Accept'            => 'application/json, text/plain, */*',
            'Origin'            => 'https://fabi.ipos.vn',
            'Referer'           => 'https://fabi.ipos.vn/',
            'accept-language'   => 'vi',
            'access_token'      => '5c885b2ef8c34fb7b1d1fad11eef7bec',
            'fabi_type'         => 'pos-cms',
            'x-client-timezone' => '25200000',
        ])->get("{$this->baseUrl}/api/reports_v1/v3/pos-cms/report/sale-by-date", [
            'company_uid'    => $companyUid,
            'brand_uid'      => $brandUid,
            'store_uid'      => $storeUid,
            'start_date'     => $startDate,
            'end_date'       => $endDate,
            'page'           => $page,
            'sort'           => 'dsc',
            'store_open_at'  => 0,
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        $this->error("API error (page {$page}): " . $response->body());
        return null;
    }
}
