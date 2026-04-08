<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Sale;
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
        // Re-sync has_sale and vat_invoice_number on all StockOuts from Sales table
        Sale::chunk(1000, function ($sales) {
            foreach ($sales as $sale) {
                $stockOut = StockOut::where('tran_id', $sale->tran_id)->first();
                if ($stockOut) {
                    $stockOut->has_sale = 1;
                    $stockOut->vat_invoice_number = $sale->vat_invoice_number;
                    $stockOut->save();
                    $sale->stockout_id = $stockOut->id;
                    $sale->save();
                } else {
                    //echo "StockOut not found for tran_id: {$sale->tran_id}" . PHP_EOL;
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
