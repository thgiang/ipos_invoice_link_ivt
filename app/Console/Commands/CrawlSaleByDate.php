<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Services\FabiClient;
use Illuminate\Console\Command;

class CrawlSaleByDate extends Command
{
    protected $signature = 'sales:crawl
                            {--start_date= : Start date (Y-m-d)}
                            {--end_date= : End date (Y-m-d)}';

    protected $description = 'Crawl all sales from Fabi sale-by-date API and save to database';

    public function __construct(private readonly FabiClient $fabi)
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

        // Fabi API uses millisecond timestamps
        $startTimestamp = strtotime($startDate) * 1000;
        $endTimestamp = (strtotime($endDate.' 23:59:59') * 1000) + 999;

        $this->info("=== Crawling sales from {$startDate} to {$endDate} ===");

        // Step 1: Login
        $this->info('Logging in to Fabi...');
        $loginData = $this->fabi->login();

        if (! $loginData) {
            $this->error('Login failed! '.$this->fabi->lastError());

            return self::FAILURE;
        }

        $companyUid = $loginData['company']['id'];
        $brandUid = $loginData['brands'][0]['id'];
        $stores = collect($loginData['stores'])->where('active', 1);

        $this->info('Logged in successfully.');
        $this->info("Company UID: {$companyUid}");
        $this->info("Brand UID: {$brandUid}");
        $this->info("Found {$stores->count()} active store(s).");

        $totalInserted = 0;
        $totalDeleted = 0;

        // Step 2: Crawl sales for each store, page by page
        foreach ($stores as $store) {
            $storeUid = $store['id'];
            $storeName = $store['store_name'];
            $this->newLine();
            $this->info("--- Store: {$storeName} ({$storeUid}) ---");

            // Pre-fetch the dedicated VAT-invoice endpoint for this store.
            // sale-by-date sometimes returns an empty vat_invoice_number even
            // when is_sync_vat = 1; the vat-invoice API always carries the full
            // number, and both APIs share the same tran_id, so we join on it.
            $vatInvoiceMap = $this->getVatInvoiceMap(
                companyUid: $companyUid,
                brandUid: $brandUid,
                storeUid: $storeUid,
                startDate: $startTimestamp,
                endDate: $endTimestamp,
            );
            $this->line('  Loaded '.count($vatInvoiceMap).' VAT invoice(s) for cross-reference.');

            $page = 1;

            do {
                $response = $this->fabi->saleByDate(
                    companyUid: $companyUid,
                    brandUid: $brandUid,
                    storeUid: $storeUid,
                    startDate: $startTimestamp,
                    endDate: $endTimestamp,
                    page: $page,
                );

                if (! $response) {
                    $this->error("API error (page {$page}): ".$this->fabi->lastError());
                    $this->warn("  Failed to fetch page {$page}. Stopping for this store.");
                    break;
                }

                $sales = $response['data'] ?? [];

                if (empty($sales)) {
                    $this->line("  Page {$page} — no more records.");
                    break;
                }

                foreach ($sales as $saleData) {
                    $paymentMethods = $saleData['payment_method'] ?? [];
                    $paymentMethodId = $paymentMethods[0]['payment_method_id'] ?? null;

                    // Đơn 0 đồng: số tiền thanh toán thực tế = 0 (thường do giảm
                    // giá 100%). total_amount là giá TRƯỚC giảm giá nên luôn > 0,
                    // không dùng để lọc được — phải xét payment_method[0].amount.
                    // Đơn 0đ không phát sinh VAT → xóa hẳn khỏi database và bỏ qua.
                    $paymentAmount = $paymentMethods[0]['amount'] ?? 0;
                    if ($paymentAmount == 0) {
                        $sourceDeli = $saleData['source_deli'] ?? '';
                        $deleted = Sale::where('tran_id', $saleData['tran_id'])->delete();
                        $this->warn("  ⚠ Đơn 0 đồng (tran_id: {$saleData['tran_id']}, source_deli: {$sourceDeli}) — xóa khỏi DB, bỏ qua hóa đơn.".($deleted ? ' [đã xóa bản ghi cũ]' : ''));
                        $totalDeleted++;

                        continue;
                    }

                    // Join with the vat-invoice API on tran_id to backfill any
                    // VAT fields the sale-by-date response left empty.
                    $vatInvoice = $vatInvoiceMap[$saleData['tran_id']] ?? null;

                    $vatInvoiceNumber = $saleData['vat_invoice_number'] ?? null;
                    if (($vatInvoiceNumber === null || $vatInvoiceNumber === '') && $vatInvoice) {
                        $vatInvoiceNumber = $vatInvoice['vat_invoice_number'] ?? null;
                    }
                    $vatInvoiceNumber = $this->padVatInvoiceNumber($vatInvoiceNumber);

                    $vatInvoiceDate = $saleData['vat_invoice_date'] ?? null;
                    if (($vatInvoiceDate === null || $vatInvoiceDate === '') && $vatInvoice) {
                        $vatInvoiceDate = $vatInvoice['vat_invoice_date'] ?? null;
                    }

                    $vatInvoiceSeries = $saleData['vat_invoice_series'] ?? null;
                    if (($vatInvoiceSeries === null || $vatInvoiceSeries === '') && $vatInvoice) {
                        $vatInvoiceSeries = $vatInvoice['inv_series'] ?? null;
                    }

                    $isSyncVat = $saleData['is_sync_vat'] ?? 0;
                    if (! $isSyncVat && $vatInvoice) {
                        $isSyncVat = $vatInvoice['is_sync_vat'] ?? $isSyncVat;
                    }

                    $created_at = date('Y-m-d H:i:s', ($saleData['tran_date'] ?? 0) / 1000);
                    $updated_at = date('Y-m-d H:i:s', ($saleData['tran_date'] ?? 0) / 1000);
                    Sale::updateOrCreate(
                        ['tran_id' => $saleData['tran_id']],
                        [
                            'sale_id' => $saleData['id'],
                            'tran_no' => $saleData['tran_no'] ?? null,
                            'tran_date' => $saleData['tran_date'] ?? null,
                            'vat_invoice_number' => $vatInvoiceNumber,
                            'vat_invoice_date' => $vatInvoiceDate,
                            'vat_invoice_series' => $vatInvoiceSeries,
                            'is_sync_vat' => $isSyncVat,
                            'total_amount' => $saleData['total_amount'] ?? 0,
                            'store_uid' => $storeUid,
                            'brand_uid' => $saleData['brand_uid'] ?? null,
                            'payment_method_id' => $paymentMethodId,
                            'created_at' => $created_at,
                            'updated_at' => $updated_at,
                        ],
                    );
                    $totalInserted++;
                }

                $this->line("  Page {$page} — ".count($sales).' sale(s) processed.');
                $page++;

            } while (true);
        }

        // Đơn 0 đồng đã lỡ lưu trong DB được xóa ngay trong vòng lặp crawl ở
        // trên (khi payment_method[0].amount == 0), nên không cần bước dọn dẹp
        // riêng dựa theo total_amount (giá trước giảm giá, luôn > 0).
        $this->newLine();
        $this->info("Đã xóa {$totalDeleted} đơn 0 đồng khỏi database.");

        // Step 3: Re-check the whole table for any vat_invoice_number that
        // hasn't been padded to 8 digits yet, and fix them.
        $this->newLine();
        $this->info('Re-checking database for unpadded vat_invoice_number...');
        $fixed = 0;

        Sale::query()
            ->whereNotNull('vat_invoice_number')
            ->where('vat_invoice_number', '<>', '')
            ->orderBy('id')
            ->chunkById(500, function ($sales) use (&$fixed) {
                foreach ($sales as $sale) {
                    $padded = $this->padVatInvoiceNumber($sale->vat_invoice_number);

                    if ($padded !== $sale->vat_invoice_number) {
                        $sale->vat_invoice_number = $padded;
                        $sale->save();
                        $fixed++;
                    }
                }
            });

        $this->info("Padded {$fixed} record(s) during re-check.");

        $this->newLine();
        $this->info("=== Done! Total sales processed: {$totalInserted} ===");

        return self::SUCCESS;
    }

    /**
     * Pad a numeric VAT invoice number to 8 digits, left-filling with zeros.
     * e.g. 24552 -> "00024552". Non-numeric / empty values are returned as-is.
     */
    private function padVatInvoiceNumber($value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $value = trim((string) $value);

        if (! ctype_digit($value)) {
            return $value;
        }

        return str_pad($value, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Fetch every VAT invoice for a store within the date range from the
     * dedicated vat-invoice endpoint, paging through all results, and return
     * them keyed by tran_id so sale records can be joined against them.
     *
     * @return array<string, array> tran_id => vat-invoice record
     */
    private function getVatInvoiceMap(
        string $companyUid,
        string $brandUid,
        string $storeUid,
        int $startDate,
        int $endDate,
    ): array {
        $map = [];
        $page = 1;

        do {
            $body = $this->fabi->vatInvoices(
                companyUid: $companyUid,
                brandUid: $brandUid,
                storeUid: $storeUid,
                startDate: $startDate,
                endDate: $endDate,
                page: $page,
            );

            if (! $body) {
                $this->warn("  VAT invoice API error (page {$page}): ".$this->fabi->lastError());
                break;
            }

            $records = $body['data'] ?? [];

            foreach ($records as $record) {
                if (! empty($record['tran_id'])) {
                    $map[$record['tran_id']] = $record;
                }
            }

            $totalPages = (int) ($body['total_pages'] ?? $page);
            $page++;
        } while ($page <= $totalPages && ! empty($records));

        return $map;
    }
}
