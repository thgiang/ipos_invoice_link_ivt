<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CrawlInvoices extends Command
{
    protected $signature = 'invoices:crawl
                            {--start_date= : Start date (Y-m-d)}
                            {--end_date= : End date (Y-m-d)}';

    protected $description = 'Crawl all VAT invoices from Fabi API and save to database';

    private string $baseUrl = 'https://posapi.ipos.vn';

    public function handle(): int
    {
        $startDate = $this->option('start_date');
        $endDate = $this->option('end_date');

        if (! $startDate || ! $endDate) {
            $this->error('Both --start_date and --end_date are required (format: Y-m-d)');
            return self::FAILURE;
        }

        // Convert to milliseconds timestamp
        $startTimestamp = strtotime($startDate) * 1000;
        $endTimestamp = (strtotime($endDate . ' 23:59:59') * 1000) + 999;

        $this->info("=== Crawling invoices from {$startDate} to {$endDate} ===");

        // Step 1: Login
        $this->info('Logging in to Fabi...');
        $loginData = $this->login();

        if (! $loginData) {
            $this->error('Login failed!');
            return self::FAILURE;
        }

        $token = $loginData['token'];
        $companyUid = $loginData['company']['id'];
        $brandUid = $loginData['brands'][0]['id'];
        $stores = collect($loginData['stores'])->where('active', 1);

        $this->info("Logged in successfully. Token obtained.");
        $this->info("Company UID: {$companyUid}");
        $this->info("Brand UID: {$brandUid}");
        $this->info("Found {$stores->count()} active store(s).");

        $totalInserted = 0;

        // Step 2: Crawl invoices for each store
        foreach ($stores as $store) {
            $storeUid = $store['id'];
            $storeName = $store['store_name'];
            $this->newLine();
            $this->info("--- Store: {$storeName} ({$storeUid}) ---");

            $page = 1;
            $totalPages = 1;

            do {
                $response = $this->getVatInvoices(
                    token: $token,
                    companyUid: $companyUid,
                    brandUid: $brandUid,
                    storeUid: $storeUid,
                    startDate: $startTimestamp,
                    endDate: $endTimestamp,
                    page: $page,
                );

                if (! $response) {
                    $this->warn("  Failed to fetch page {$page}. Skipping...");
                    break;
                }

                $totalPages = $response['total_pages'] ?? 1;
                $invoices = $response['data'] ?? [];

                foreach ($invoices as $invoiceData) {
                    Invoice::updateOrCreate(
                        ['tran_id' => $invoiceData['tran_id']],
                        [
                            'vat_invoice_number'    => $invoiceData['vat_invoice_number'] ?? null,
                            'tran_id_sync_vat'      => $invoiceData['tran_id_sync_vat'] ?? null,
                            'vat_invoice_date'      => $invoiceData['vat_invoice_date'] ?? null,
                            'inv_buyerDisplayName'  => $invoiceData['inv_buyerDisplayName'] ?? null,
                            'inv_buyerLegalName'    => $invoiceData['inv_buyerLegalName'] ?? null,
                            'inv_buyerTaxCode'      => $invoiceData['inv_buyerTaxCode'] ?? null,
                            'inv_buyerIdentityCard' => $invoiceData['inv_buyerIdentityCard'] ?? null,
                            'inv_buyerAddressLine'  => $invoiceData['inv_buyerAddressLine'] ?? null,
                            'inv_buyerEmail'        => $invoiceData['inv_buyerEmail'] ?? null,
                            'sdtnmua'               => $invoiceData['sdtnmua'] ?? null,
                            'inv_buyerBankName'     => $invoiceData['inv_buyerBankName'] ?? null,
                            'inv_buyerBankAccount'  => $invoiceData['inv_buyerBankAccount'] ?? null,
                            'budget_unit_code'      => $invoiceData['budget_unit_code'] ?? null,
                            'passport_number'       => $invoiceData['passport_number'] ?? null,
                            'note'                  => $invoiceData['note'] ?? null,
                            'is_sync_vat'           => $invoiceData['is_sync_vat'] ?? 0,
                            'total_amount'          => $invoiceData['total_amount'] ?? 0,
                            'discount_amount'       => $invoiceData['discount_amount'] ?? 0,
                            'enable_vat_cms'        => $invoiceData['enable_vat_cms'] ?? 0,
                            'enable_vat_pos'        => $invoiceData['enable_vat_pos'] ?? 0,
                            'vat_amount'            => $invoiceData['vat_amount'] ?? 0,
                            'list_tran_no'          => $invoiceData['list_tran_no'] ?? null,
                            'error_message'         => $invoiceData['error_message'] ?? null,
                            'store_uid'             => $storeUid,
                        ],
                    );
                    $totalInserted++;
                }

                $this->line("  Page {$page}/{$totalPages} — " . count($invoices) . " invoice(s) processed.");
                $page++;
            } while ($page <= $totalPages);
        }

        $this->newLine();
        $this->info("=== Done! Total invoices processed: {$totalInserted} ===");

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

    private function getVatInvoices(
        string $token,
        string $companyUid,
        string $brandUid,
        string $storeUid,
        int $startDate,
        int $endDate,
        int $page,
        int $resultsPerPage = 50,
    ): ?array {
        $response = Http::withHeaders([
            'Authorization'     => $token,
            'Accept'            => 'application/json, text/plain, */*',
            'Referer'           => 'https://fabi.ipos.vn/',
            'accept-language'   => 'vi',
            'access_token'      => '5c885b2ef8c34fb7b1d1fad11eef7bec',
            'fabi_type'         => 'pos-cms',
            'x-client-timezone' => '25200000',
        ])->get("{$this->baseUrl}/api/v3/pos-cms/vat-invoice", [
            'company_uid'      => $companyUid,
            'brand_uid'        => $brandUid,
            'store_uid'        => $storeUid,
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'page'             => $page,
            'results_per_page' => $resultsPerPage,
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        $this->error("API error (page {$page}): " . $response->body());
        return null;
    }
}
