<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use App\Models\StockOut;

class BuildExcelIvtFile extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:build-excel-ivt-file';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
       $stores = Invoice::select('store_uid')->groupBy('store_uid')->get();
       foreach ($stores as $store) {
        $this->buildExcelFile($store->store_uid);
       }
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
                        ];
                        continue;
                    }

                    $detail = json_decode($stockOut->detail);
                    
                    foreach ($detail->list_item as $item) {
                        $nvls = $this->congThuc($item);

                        foreach ($nvls as $nvl) {
                            $fileRows[$filePath][] = [
                                $kyHieu,
                                $invoice->vat_invoice_number,
                                date('Y-m-d', $invoice->vat_invoice_date / 1000),
                                $stockOut->gi_id,
                                $nvl->item_id,
                                $nvl->item_name,
                                $nvl->quantity,
                                $nvl->unit_id,
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
            // Sort invoiceNumbers
            sort($invoiceNumbers);
            $txtPath = $txtDir . '/Tong_hop_' . $kyHieu . '.txt';
            file_put_contents($txtPath, implode(PHP_EOL, $invoiceNumbers) . PHP_EOL);
        }

        $this->info('Done. Generated ' . count($fileRows) . ' Excel file(s) and ' . count($kyHieuInvoices) . ' txt file(s).');
    }

    private function congThuc(object $item): array
    {
        return [$item];
    }

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

    public function getKyHieu($invoice) {
        // Example: "1C26MYA" trong đó 1C là cố định, 25 hoặc 26 là năm 2025 hoặc 2026. 3 kí tự cuối là cửa hàng
        $defaultForm = "1C__NAM__MYA";
        $formKyHieus = array(
            "b252a82f-73e5-47b4-94c0-0a34daae2549" => "1C__NAM__MYA",
            "96da0392-e0de-4575-a83c-82412d554812" => "1C__NAM__MYB",
            "82edbf77-f9d8-454a-be63-9ff78d7c9f9e"=> "1C__NAM__MYC",
            "fbc55871-1b0b-43a3-a26b-c65302b1b659"=> "1C__NAM__MYE",
        );

        $storeUid = $invoice->store_uid;
        $year = date('y', $invoice->vat_invoice_date / 1000);
        $form = $formKyHieus[$storeUid] ?? $defaultForm;
        $kyHieu = str_replace("__NAM__", $year, $form);

        return $kyHieu;
    }
}
