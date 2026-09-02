<?php

namespace App\Console\Commands;

use App\Models\StockOut;
use App\Services\IvtClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

class GetStockOutDetail extends Command
{
    protected $signature = 'stock-outs:get-detail';

    protected $description = 'Fetch detail for stock-out records that have invoices but no detail yet';

    public function __construct(private readonly IvtClient $ivt)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('=== Fetching stock-out details ===');

        // Step 1: Login to IVT
        $this->info('Logging in to IVT...');

        if (! $this->ivt->login()) {
            $this->error('IVT login failed! '.$this->ivt->lastError());

            return self::FAILURE;
        }

        $this->info('Logged in successfully. Token obtained.');

        // Step 2: Chunk through StockOuts with has_sale=1 and detail=null
        $totalProcessed = 0;
        $failed = false;

        StockOut::where('has_sale', 1)
            ->whereNull('detail')
            ->chunkById(100, function ($stockOuts) use (&$totalProcessed, &$failed) {
                foreach ($stockOuts as $stockOut) {
                    if ($failed) {
                        return false;
                    }

                    try {
                        $detail = $this->ivt->stockOutDetail($stockOut->ivt_id);

                        // If failed (non-exception), retry once with a new token
                        if ($detail === null) {
                            $this->warn('API error: '.$this->ivt->lastError());
                            $this->warn('Token may have expired. Retrying login...');

                            if (! $this->ivt->login()) {
                                $this->error('IVT re-login failed! '.$this->ivt->lastError());
                                $failed = true;

                                return false;
                            }

                            $this->info('Re-login successful. Retrying API call...');
                            $detail = $this->ivt->stockOutDetail($stockOut->ivt_id);

                            if ($detail === null) {
                                $this->error("Failed to fetch detail for gi_id={$stockOut->gi_id} (ivt_id={$stockOut->ivt_id}) after retry. Stopping.");
                                $failed = true;

                                return false;
                            }
                        }

                        $stockOut->detail = json_encode($detail);
                        $stockOut->save();
                        $totalProcessed++;

                        $this->line("  ✓ {$stockOut->gi_id} — detail saved.");
                    } catch (ConnectionException $e) {
                        $this->warn("  ⚠ Skipping gi_id={$stockOut->gi_id} (ivt_id={$stockOut->ivt_id}): {$e->getMessage()}");

                        continue;
                    }
                }
            });

        if ($failed) {
            $this->newLine();
            $this->error("Stopped due to error. Total processed before failure: {$totalProcessed}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("=== Done! Total stock-out details fetched: {$totalProcessed} ===");

        return self::SUCCESS;
    }
}
