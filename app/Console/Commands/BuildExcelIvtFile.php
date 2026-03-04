<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\StockOut;

class BuildExcelIvtFile extends Command
{
    protected $signature = 'app:build-excel-ivt-file';

    protected $description = 'Build Excel IVT files from invoices and stock-outs';

    private string $baseUrl = 'https://apiivt.ipos.vn';

    /** @var array<string, object> Recipes keyed by item_id */
    private array $recipes = [];

    /**
     * Unit conversion table per item_id.
     * Format: item_id => [from_unit_id, to_unit_id, ratio]
     * ratio means: 1 from_unit = ratio to_unit
     * Example: 1 COC = 200 ML
     */
    private array $unitConversions = [
        'COT_TRADAOCAMSA' => ['from' => 'COC', 'to' => 'ML', 'ratio' => 200],
        'TP_KEMMAN'       => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 40],
        'TP_THACHDAUNHO'  => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 70],
        'TP_THACHTHAIDO'  => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 70],
        'TP_TRANCHAUDEN'  => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 80],
        'TP_TRANCHAUDUONGDEN'  => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 80],
        'TP_THACHTRADAO'  => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 70],
        'TP_THACHTHAIXANH'  => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 70],
        'TP_SOTCARAMEL'  => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 30],
    ];

    private array $overwriteWarehouse = [
        'MANTOPPING' => 'BTRUNGLIET',
        'TP_TRANCHAUCUIBUOI' => 'BTRUNGLIET',
        'TP_KEMPHOMAIMUOI' => 'BTRUNGLIET',
        'THACHPHOMAI' => 'BTRUNGLIET',
        'TP_TRANCHAUCUNANG' => 'BTRUNGLIET',
        'TP_TRANCHAUKHOAIMON' => 'BTRUNGLIET',
        'TP_SOTKHOAIMON' => 'BTRUNGLIET',
        'TP_SOTCARAMEL' => 'BTRUNGLIET',
        'KHUCBACHCHANMEOBE' => 'BTRUNGLIET',
        'KHUCBACHBETRUNG' => 'BTRUNGLIET',
        'PHOMAIMAN' => 'BTRUNGLIET',
        'KHUCBACHTANG' => 'BTRUNGLIET',
        'KHUCBACHCHANMEOTO' => 'BTRUNGLIET',
        'TP_SOTHATDE' => 'BTRUNGLIET',
    ];

    public function handle(): int
    {
        // Step 1: Load recipes
        $this->info('Loading recipes...');
        $this->recipes = $this->loadRecipes();
        $this->info('Loaded ' . count($this->recipes) . ' recipe(s).');

        // Step 2: Build Excel files per store
        $stores = Invoice::select('store_uid')->groupBy('store_uid')->get();

        foreach ($stores as $store) {
            $this->info("Building Excel for store: {$store->store_uid}");
            $this->buildExcelFile($store->store_uid);
        }

        return self::SUCCESS;
    }

    public function buildExcelFile(string $storeUid): void
    {
        $headers = [
            'ky_hieu',
            'vat_invoice_number',
            'vat_invoice_date',
            'gi_id',
            'item_id',
            'item_name',
            'quantity',
            'unit_id',
            'warehouse',
        ];

        // Collect all rows grouped by filePath
        $fileRows = [];
        $kyHieuInvoices = [];

        Invoice::where('store_uid', $storeUid)
            ->orderBy('vat_invoice_number', 'asc')
            ->chunk(100, function ($invoices) use (&$fileRows, &$kyHieuInvoices) {
                $tranIds = $invoices->pluck('tran_id');
                $stockOuts = StockOut::whereIn('tran_id', $tranIds)->get()->keyBy('tran_id');

                foreach ($invoices as $invoice) {
                    $stockOut = $stockOuts->get($invoice->tran_id);
                    $kyHieu = $this->getKyHieu($invoice);
                    $kyHieuInvoices[$kyHieu][] = $invoice->vat_invoice_number;
                    $filePath = storage_path(
                        'app/excel-ivt/' . $kyHieu . '/' . ((int) ($invoice->vat_invoice_number / 100)) . '.xlsx'
                    );

                    if (! $stockOut || ! ($detail = json_decode($stockOut?->detail)) || empty($detail->list_item)) {
                        $this->warn("StockOut/Detail not found for tran_id: {$invoice->tran_id}");
                        $fileRows[$filePath][] = [
                            $kyHieu,
                            $invoice->vat_invoice_number,
                            date('Y-m-d', $invoice->vat_invoice_date / 1000),
                            $stockOut?->gi_id ?? '',
                            'ONGNHUATO',
                            'Lỗi phần mềm IVT',
                            0,
                            'ONG',
                            $kyHieu,
                        ];
                        continue;
                    }


                    foreach ($detail->list_item as $item) {
                        $nvls = $this->congThuc($item);

                        foreach ($nvls as $nvl) {
                            if (empty($nvl->warehouse)) {
                                $nvl->warehouse = $kyHieu;
                            }
                            $fileRows[$filePath][] = [
                                $kyHieu,
                                $invoice->vat_invoice_number,
                                date('Y-m-d', $invoice->vat_invoice_date / 1000),
                                $stockOut->gi_id,
                                $nvl->item_id,
                                $nvl->item_name,
                                $nvl->quantity,
                                $nvl->unit_id,
                                $nvl->warehouse
                            ];
                        }
                    }
                }
            });

        // Write each file once
        foreach ($fileRows as $filePath => $rows) {
            $this->writeToExcel($filePath, $headers, $rows);
        }

        // Write kyHieu summary txt files
        $txtDir = storage_path('app/excel-ivt');
        foreach ($kyHieuInvoices as $kyHieu => $invoiceNumbers) {
            sort($invoiceNumbers);
            $txtPath = $txtDir . '/Dem_hoa_don_thue_' . $kyHieu . '.txt';
            file_put_contents($txtPath, implode(PHP_EOL, $invoiceNumbers) . PHP_EOL);
        }

        $this->info('Done. Generated ' . count($fileRows) . ' Excel file(s) and ' . count($kyHieuInvoices) . ' txt file(s).');
    }

    /**
     * Expand a composite item into its raw materials using its recipe.
     * If no recipe exists, returns the item as-is.
     *
     * @return object[]
     */
    private function congThuc(object $item): array
    {
        $item->warehouse = '';
        if (in_array($item->item_id, array_keys($this->overwriteWarehouse))) {
            $item->warehouse = $this->overwriteWarehouse[$item->item_id];
        }

        $recipe = $this->recipes[$item->item_id] ?? null;

        if (! $recipe) {
            // No recipe — this is already a raw material
            return [$item];
        }

        // Determine quantity multiplier
        // The recipe defines materials for 1 unit of recipe->unit_id
        // The stock-out item has $item->quantity in $item->unit_id
        $itemQuantity = (float) $item->quantity;

        if ($item->unit_id !== $recipe->unit_id) {
            // Need unit conversion
            $conversion = $this->unitConversions[$item->item_id] ?? null;

            if (! $conversion) {
                $this->error("Unit conversion not found for item_id= {$item->item_id} : stock-out unit={$item->unit_id}, recipe unit={$recipe->unit_id}. Stopping!");
                exit(1);
            }

            // Convert item quantity to recipe unit
            // e.g. item is 200 ML, recipe is per 1 COC, conversion: 1 COC = 200 ML
            // So: 200 ML / 200 = 1 COC
            if ($item->unit_id === $conversion['to']) {
                $itemQuantity = $itemQuantity / $conversion['ratio'];
            } elseif ($item->unit_id === $conversion['from']) {
                $itemQuantity = $itemQuantity * $conversion['ratio'];
            } else {
                $this->error("Unit mismatch for item_id={$item->item_id}: stock-out unit={$item->unit_id} not in conversion [{$conversion['from']}, {$conversion['to']}]. Stopping!");
                exit(1);
            }
        }

        // Expand recipe details, multiplying quantities
        $materials = [];

        foreach ($recipe->recipe_details as $detail) {
            $materials[] = (object) [
                'item_id'   => $detail->item_id,
                'item_name' => $detail->item_name,
                'unit_id'   => $detail->unit_id,
                'quantity'  => round($detail->quantity * $itemQuantity, 4),
                'warehouse' => $item->warehouse,
            ];
        }

        // Echo Món ABC có công thức chế biến, đã quy đổi XXX unit_id thành các thành phần sau:
        // echo "\nMón {$item->item_name} có công thức chế biến, đã quy đổi {$item->quantity} {$item->unit_id} thành các thành phần sau:\n";
        // foreach ($materials as $material) {
        //     echo "- {$material->item_name} ({$material->quantity} {$material->unit_id})\n";
        // }
        return $materials;
    }

    // ─── Recipe Loading ────────────────────────────────────────────

    /**
     * Load recipes from cache or API.
     *
     * @return array<string, object>
     */
    private function loadRecipes(): array
    {
        $cachePath = storage_path('app/excel-ivt/recipes_cache.json');

        // Use cache if it exists and is less than 24 hours old
        if (file_exists($cachePath) && (time() - filemtime($cachePath)) < 86400) {
            $this->info('Using cached recipes.');
            $cached = json_decode(file_get_contents($cachePath));
            $recipes = [];
            foreach ($cached as $recipe) {
                $recipes[$recipe->item_id] = $recipe;
            }

            return $recipes;
        }

        // Fetch from API
        $userToken = $this->loginIvt();

        if (! $userToken) {
            $this->error('IVT login failed! Cannot load recipes.');
            exit(1);
        }

        $allRecipes = [];
        $page = 1;
        $totalPages = 1;

        do {
            $response = $this->getRecipes($userToken, $page);

            if (! $response) {
                $this->error("Failed to fetch recipes page {$page}.");
                exit(1);
            }

            $totalPages = $response['total_pages'] ?? 1;

            foreach ($response['data'] ?? [] as $item) {
                $allRecipes[] = $item;
            }

            $this->line("  Recipes page {$page}/{$totalPages} — " . count($response['data'] ?? []) . ' recipe(s).');
            $page++;
        } while ($page <= $totalPages);

        // Save cache
        $cacheDir = dirname($cachePath);
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cachePath, json_encode($allRecipes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Recipes cached to ' . $cachePath);

        // Index by item_id
        $recipes = [];
        foreach ($allRecipes as $item) {
            $recipes[$item['item_id']] = (object) $item;
            // Convert recipe_details to objects
            $recipes[$item['item_id']]->recipe_details = array_map(
                fn ($d) => (object) $d,
                $item['recipe_details'] ?? []
            );
        }

        return $recipes;
    }

    private function loginIvt(): ?string
    {
        $response = Http::withHeaders([
            'Content-Type'                => 'application/json',
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
            'Referer'                     => 'https://ivt.ipos.vn/',
            'Access-Control-Allow-Origin' => 'https://apiivt.ipos.vn',
        ])->post("{$this->baseUrl}/api/main/v1/auth/login", [
            'user_email' => env('IVT_EMAIL'),
            'password'   => env('IVT_PASSWORD'),
        ]);

        if ($response->successful()) {
            return $response->json('data.user_token');
        }

        $this->error('IVT Login failed: ' . $response->body());

        return null;
    }

    private function getRecipes(string $userToken, int $page, int $pageSize = 50): ?array
    {
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
        ])->get("{$this->baseUrl}/api/main/v2/catalog/recipe", [
            'active'    => 1,
            'page_size' => $pageSize,
            'page'      => $page,
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        $this->error("Recipes API error (page {$page}): " . $response->body());

        return null;
    }

    // ─── Excel Writing ─────────────────────────────────────────────

    private function writeToExcel(string $filePath, array $headers, array $rows): void
    {
        $dir = dirname($filePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($filePath)) {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $file = $reader->load($filePath);
            $sheet = $file->getActiveSheet();
        } else {
            $file = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $file->getActiveSheet();
            $sheet->fromArray($headers, null, 'A1');
        }

        $nextRow = $sheet->getHighestRow() + 1;

        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A' . $nextRow);
            $nextRow++;
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($file);
        $writer->save($filePath);

        // Free memory
        $file->disconnectWorksheets();
        unset($file, $writer, $sheet);
    }

    // ─── Helpers ───────────────────────────────────────────────────

    public function getKyHieu($invoice): string
    {
        $defaultForm = '1C__NAM__MYA';
        $formKyHieus = [
            'b252a82f-73e5-47b4-94c0-0a34daae2549' => '1C__NAM__MYA',
            '96da0392-e0de-4575-a83c-82412d554812' => '1C__NAM__MYB',
            '82edbf77-f9d8-454a-be63-9ff78d7c9f9e' => '1C__NAM__MYC',
            'fbc55871-1b0b-43a3-a26b-c65302b1b659' => '1C__NAM__MYE',
        ];

        $storeUid = $invoice->store_uid;
        $year = date('y', $invoice->vat_invoice_date / 1000);
        $form = $formKyHieus[$storeUid] ?? $defaultForm;

        return str_replace('__NAM__', $year, $form);
    }
}

