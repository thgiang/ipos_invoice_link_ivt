<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\StockOut;
use App\Services\IvtClient;
use Illuminate\Console\Command;

class BuildExcelIvtFile extends Command
{
    protected $signature = 'app:build-excel-ivt-file {start_date?} {end_date?}';

    protected $description = 'Build Excel IVT files from invoices and stock-outs by date range (YYYY-MM-DD)';

    /** @var array<string, object> Recipes keyed by item_id */
    private array $recipes = [];

    /** @var array<string, true> Output paths already re-created during this run */
    private array $touchedFiles = [];

    /** @var array<string, array<int>> Invoice numbers grouped by ky_hieu for summary TXT */
    private array $kyHieuInvoices = [];

    /**
     * Monthly aggregation data.
     * Format: [kyHieu][month][item_id] => ['item_name', 'quantity', 'unit_id', 'warehouse']
     */
    private array $monthlyAggregation = [];

    /**
     * Payment method aggregation data.
     * Format: [kyHieu][month][payment_method_id] => ['sale_count', 'total_amount']
     */
    private array $paymentMethodAggregation = [];

    /**
     * Unit conversion table per item_id.
     * Format: item_id => [from_unit_id, to_unit_id, ratio]
     * ratio means: 1 from_unit = ratio to_unit
     * Example: 1 COC = 200 ML
     */
    private array $unitConversions = [
        'COT_TRADAOCAMSA' => ['from' => 'COC', 'to' => 'ML', 'ratio' => 200],
        'TP_KEMMAN' => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 40],
        'TP_THACHDAUNHO' => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 70],
        'TP_THACHTHAIDO' => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 70],
        'TP_TRANCHAUDEN' => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 80],
        'TP_TRANCHAUDUONGDEN' => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 80],
        'TP_THACHTRADAO' => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 70],
        'TP_THACHTHAIXANH' => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 70],
        'TP_SOTCARAMEL' => ['from' => 'PHAN', 'to' => 'GR', 'ratio' => 30],
        'MANTOPPING' => ['from' => 'KG', 'to' => 'GR', 'ratio' => 1000],
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
        'TP_KEMCOM' => 'BTRUNGLIET',
        'TP_SOTCOM' => 'BTRUNGLIET',
    ];

    public function __construct(private readonly IvtClient $ivt)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Step 1: Load recipes
        $this->info('Loading recipes...');
        $this->recipes = $this->loadRecipes();
        $this->info('Loaded '.count($this->recipes).' recipe(s).');

        // Step 2: Determine date range
        $startDate = $this->argument('start_date') ?: date('Y-m-d');
        $endDate = $this->argument('end_date') ?: $startDate;

        $this->info("Processing invoices from {$startDate} to {$endDate}");

        try {
            $period = new \DatePeriod(
                new \DateTime($startDate),
                new \DateInterval('P1D'),
                (new \DateTime($endDate))->modify('+1 day')
            );
        } catch (\Exception $e) {
            $this->error('Invalid date format. Please use YYYY-MM-DD.');

            return self::FAILURE;
        }

        // Step 3: Build Excel files per store per day
        $stores = Sale::select('store_uid')->groupBy('store_uid')->get();

        foreach ($period as $dt) {
            $date = $dt->format('Y-m-d');
            $this->info("--- Processing date: {$date} ---");

            foreach ($stores as $store) {
                $this->buildExcelFile($store->store_uid, $date);
            }
        }

        // Step 4: Write kyHieu summary txt files
        $txtDir = storage_path('app/excel-ivt');
        foreach ($this->kyHieuInvoices as $kyHieu => $invoiceNumbers) {
            $invoiceNumbers = array_values(array_filter($invoiceNumbers, fn ($n) => trim((string) $n) !== ''));
            sort($invoiceNumbers);
            $txtPath = $txtDir.'/Dem_hoa_don_thue_'.$kyHieu.'.txt';
            file_put_contents($txtPath, implode(PHP_EOL, $invoiceNumbers).PHP_EOL);
            $this->info("Generated summary for prefix: {$kyHieu}");
        }

        // Step 5: Write monthly aggregation Excel files
        $this->info('Generating monthly summary Excel files...');
        foreach ($this->monthlyAggregation as $kyHieu => $months) {
            foreach ($months as $month => $items) {
                $filePath = storage_path("app/excel-ivt/{$kyHieu}_tong_hop_{$month}.xlsx");

                $rows = [];
                foreach ($items as $itemId => $data) {
                    $rows[] = [
                        $kyHieu,
                        '', // vat_invoice_number
                        $month, // vat_invoice_date (as month)
                        '', // gi_id
                        $itemId,
                        $data['item_name'],
                        $data['quantity'],
                        $data['unit_id'],
                        $data['warehouse'],
                        $data['total_amount'],
                        $data['payment_method_id'],
                    ];
                }

                $this->writeToExcel($filePath, [
                    'ky_hieu',
                    'vat_invoice_number',
                    'vat_invoice_date',
                    'gi_id',
                    'item_id',
                    'item_name',
                    'quantity',
                    'unit_id',
                    'warehouse',
                    'total_amount',
                    'payment_method_id',
                ], $rows);
                $this->info("  Monthly summary generated: {$filePath}");
            }
        }

        // Step 6: Write payment method aggregation Excel files
        $this->info('Generating payment method summary Excel files...');
        foreach ($this->paymentMethodAggregation as $kyHieu => $months) {
            foreach ($months as $month => $methods) {
                $filePath = storage_path("app/excel-ivt/{$kyHieu}_thanh_toan_{$month}.xlsx");

                uasort($methods, fn ($a, $b) => $b['total_amount'] <=> $a['total_amount']);

                $rows = [];
                foreach ($methods as $methodId => $data) {
                    $rows[] = [
                        $kyHieu,
                        $month,
                        $methodId,
                        $data['sale_count'],
                        $data['total_amount'],
                    ];
                }

                $this->writeToExcel($filePath, [
                    'ky_hieu',
                    'month',
                    'payment_method_id',
                    'sale_count',
                    'total_amount',
                ], $rows);
                $this->info("  Payment method summary generated: {$filePath}");
            }
        }

        return self::SUCCESS;
    }

    public function buildExcelFile(string $storeUid, string $date): void
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
            'total_amount',
            'payment_method_id',
        ];

        $startMs = strtotime($date.' 00:00:00') * 1000;
        $endMs = strtotime($date.' 23:59:59') * 1000 + 999;

        // Collect all rows grouped by filePath for this specific day
        $fileRows = [];

        $daySales = Sale::where('store_uid', $storeUid)
            ->whereBetween('vat_invoice_date', [$startMs, $endMs])
            ->orderBy('vat_invoice_number', 'asc')
            ->get();

        if ($daySales->isEmpty()) {
            return;
        }

        $this->info("  Store {$storeUid}: found ".$daySales->count().' sale(s) with VAT invoices.');

        $tranIds = $daySales->pluck('tran_id');
        $stockOuts = StockOut::whereIn('tran_id', $tranIds)->get()->keyBy('tran_id');

        foreach ($daySales as $invoice) {
            $stockOut = $stockOuts->get($invoice->tran_id);
            $kyHieu = $this->getKyHieu($invoice);
            $this->kyHieuInvoices[$kyHieu][] = $invoice->vat_invoice_number;

            // Aggregate payment method data
            $month = date('Y-m', strtotime($date));
            $methodId = $invoice->payment_method_id ?? 'UNKNOWN';
            if (! isset($this->paymentMethodAggregation[$kyHieu][$month][$methodId])) {
                $this->paymentMethodAggregation[$kyHieu][$month][$methodId] = [
                    'sale_count' => 0,
                    'total_amount' => 0.0,
                ];
            }
            $this->paymentMethodAggregation[$kyHieu][$month][$methodId]['sale_count']++;
            $this->paymentMethodAggregation[$kyHieu][$month][$methodId]['total_amount'] += (float) $invoice->total_amount;

            $filePath = storage_path(
                'app/excel-ivt/'.$kyHieu.'/'.$date.'.xlsx'
            );

            if (! $stockOut || ! ($detail = json_decode($stockOut?->detail)) || empty($detail->list_item)) {
                $this->warn("  Warning: StockOut/Detail not found for tran_id: {$invoice->tran_id}");
                $fileRows[$filePath][] = [
                    $kyHieu,
                    $invoice->vat_invoice_number,
                    $date,
                    $stockOut?->gi_id ?? '',
                    'ONGNHUATO',
                    'Lỗi phần mềm IVT',
                    0,
                    'ONG',
                    $kyHieu,
                    $invoice->total_amount,
                    $invoice->payment_method_id,
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
                        $date,
                        $stockOut->gi_id,
                        $nvl->item_id,
                        $nvl->item_name,
                        $nvl->quantity,
                        $nvl->unit_id,
                        $nvl->warehouse,
                        $invoice->total_amount,
                        $invoice->payment_method_id,
                    ];

                    // Aggregation per kyHieu and month (YYYY-MM)
                    $month = date('Y-m', strtotime($date));
                    if (! isset($this->monthlyAggregation[$kyHieu][$month][$nvl->item_id])) {
                        $this->monthlyAggregation[$kyHieu][$month][$nvl->item_id] = [
                            'item_name' => $nvl->item_name,
                            'quantity' => 0,
                            'unit_id' => $nvl->unit_id,
                            'warehouse' => $nvl->warehouse,
                            'total_amount' => 0.0,
                            'payment_method_id' => '',
                        ];
                    }

                    // Check for unit consistency
                    if ($this->monthlyAggregation[$kyHieu][$month][$nvl->item_id]['unit_id'] !== $nvl->unit_id) {
                        $this->error("ALARM: Unit mismatch for item '{$nvl->item_id}' in prefix '{$kyHieu}', month '{$month}'!");
                        $this->error('Existing unit: '.$this->monthlyAggregation[$kyHieu][$month][$nvl->item_id]['unit_id']);
                        $this->error('New unit: '.$nvl->unit_id);
                        $this->error('Stopping process immediately.');
                        exit(1);
                    }

                    $this->monthlyAggregation[$kyHieu][$month][$nvl->item_id]['quantity'] += $nvl->quantity;
                    $this->monthlyAggregation[$kyHieu][$month][$nvl->item_id]['total_amount'] += (float) $invoice->total_amount;
                }
            }
        }

        // Write each file once
        foreach ($fileRows as $filePath => $rows) {
            $this->writeToExcel($filePath, $headers, $rows);
        }
    }

    /**
     * Expand a composite item into its raw materials using its recipe.
     * If no recipe exists, returns the item as-is.
     *
     * A material produced by a recipe may itself be a composite item with its
     * own recipe (depth 2, 3, ...). In that case we keep expanding recursively
     * until only raw materials (items without a recipe) remain.
     *
     * @param  string  $parentWarehouse  Warehouse inherited from the parent item;
     *                                   used for sub-materials that have no
     *                                   warehouse override of their own.
     * @param  array<string>  $stack  item_ids on the current expansion path,
     *                                used to break recipe cycles.
     * @return object[]
     */
    private function congThuc(object $item, string $parentWarehouse = '', array $stack = []): array
    {
        $item->warehouse = $parentWarehouse;
        if (in_array($item->item_id, array_keys($this->overwriteWarehouse))) {
            $item->warehouse = $this->overwriteWarehouse[$item->item_id];
        }

        $recipe = $this->recipes[$item->item_id] ?? null;

        if (! $recipe) {
            // No recipe — this is already a raw material
            return [$item];
        }

        // Guard against recipe cycles (e.g. A -> B -> A) which would recurse forever.
        if (in_array($item->item_id, $stack, true)) {
            $this->error(
                'Recipe cycle detected for item_id='.$item->item_id.
                ' (path: '.implode(' -> ', array_merge($stack, [$item->item_id])).'). '.
                'Treating it as a raw material to stop recursion.'
            );

            return [$item];
        }
        $stack[] = $item->item_id;

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
            $material = (object) [
                'item_id' => $detail->item_id,
                'item_name' => $detail->item_name,
                'unit_id' => $detail->unit_id,
                'quantity' => round($detail->quantity * $itemQuantity, 4),
                'warehouse' => $item->warehouse,
            ];

            // A material can itself be a composite item (has its own recipe).
            // Recurse so it is expanded down to raw materials as well.
            // Sub-materials inherit this item's warehouse unless they define
            // their own override inside congThuc().
            foreach ($this->congThuc($material, $item->warehouse, $stack) as $expanded) {
                $materials[] = $expanded;
            }
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
        if (! $this->ivt->login()) {
            $this->error('IVT login failed! Cannot load recipes. '.$this->ivt->lastError());
            exit(1);
        }

        $allRecipes = [];
        $page = 1;
        $totalPages = 1;

        do {
            $response = $this->ivt->recipes($page);

            if (! $response) {
                $this->error("Failed to fetch recipes page {$page}: ".$this->ivt->lastError());
                exit(1);
            }

            $totalPages = $response['total_pages'] ?? 1;

            foreach ($response['data'] ?? [] as $item) {
                $allRecipes[] = $item;
            }

            $this->line("  Recipes page {$page}/{$totalPages} — ".count($response['data'] ?? []).' recipe(s).');
            $page++;
        } while ($page <= $totalPages);

        // Save cache
        $cacheDir = dirname($cachePath);
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cachePath, json_encode($allRecipes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Recipes cached to '.$cachePath);

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

    // ─── Excel Writing ─────────────────────────────────────────────

    /**
     * Append rows to an Excel file, creating it on first use.
     *
     * A single file is written to several times within one run — stores sharing
     * a ky_hieu land in the same daily file — so appending is deliberate. What
     * is not deliberate is appending to a file left behind by a *previous* run:
     * that silently doubles every row when the same date range is rebuilt. So
     * the first write of a run discards any existing file.
     *
     * @param  string[]  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function writeToExcel(string $filePath, array $headers, array $rows): void
    {
        $dir = dirname($filePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! isset($this->touchedFiles[$filePath]) && file_exists($filePath)) {
            unlink($filePath);
            $this->line("  Removed stale file from a previous run: {$filePath}");
        }

        $this->touchedFiles[$filePath] = true;

        if (file_exists($filePath)) {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx;
            $file = $reader->load($filePath);
            $sheet = $file->getActiveSheet();
        } else {
            $file = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
            $sheet = $file->getActiveSheet();
            $sheet->fromArray($headers, null, 'A1');
        }

        $nextRow = $sheet->getHighestRow() + 1;

        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A'.$nextRow);
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
