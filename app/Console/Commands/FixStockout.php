<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Invoice;
use App\Models\StockOut;

class FixStockout extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fix-stockout';

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
        // // Get all tran_id from invoice table, chunk by 100
        $invoices = Invoice::chunk(100, function ($invoices) {
            foreach ($invoices as $invoice) {
                $tranId = $invoice->tran_id;
                $stockOut = StockOut::where('tran_id', $tranId)->first();
                if ($stockOut) {
                    $stockOut->has_invoice = 1;
                    $stockOut->vat_invoice_number = $invoice->vat_invoice_number;
                    $stockOut->save();
                } else {
                    echo "StockOut not found for tran_id: {$tranId}" . PHP_EOL;
                }
            }
        });

        // Get all stockout by chunk then fix TranId from descripption
        // $stockOut = StockOut::chunk(100, function ($stockOuts) {
        //     foreach ($stockOuts as $stockOut) {
        //         $tranId = $this->extractTranId($stockOut->description);
        //         $stockOut->tran_id = $tranId;
        //         $stockOut->save();
        //     }
        // });
    }

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
}
