<?php

namespace App\Console\Commands;

use App\Models\StockOut;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class GetStockOutDetail extends Command
{
    protected $signature = 'stock-outs:get-detail';

    protected $description = 'Fetch detail for stock-out records that have invoices but no detail yet';

    private string $baseUrl = 'https://apiivt.ipos.vn';

    private ?string $userToken = null;

    public function handle(): int
    {
        $this->info('=== Fetching stock-out details ===');

        // Step 1: Login to IVT
        $this->info('Logging in to IVT...');
        $this->userToken = $this->loginIvt();

        if (! $this->userToken) {
            $this->error('IVT login failed!');
            return self::FAILURE;
        }

        $this->info('Logged in successfully. Token obtained.');

        // Step 2: Chunk through StockOuts with has_invoice=1 and detail=null
        $totalProcessed = 0;
        $failed = false;

        StockOut::where('has_sale', 1)
            ->whereNull('detail')
            ->chunkById(100, function ($stockOuts) use (&$totalProcessed, &$failed) {
                foreach ($stockOuts as $stockOut) {
                    if ($failed) {
                        return false;
                    }

                    $detail = $this->fetchDetail($stockOut->ivt_id);

                    // If failed, retry once with a new token
                    if ($detail === null) {
                        $this->warn("Token may have expired. Retrying login...");
                        $this->userToken = $this->loginIvt();

                        if (! $this->userToken) {
                            $this->error('IVT re-login failed! Stopping.');
                            $failed = true;
                            return false;
                        }

                        $this->info('Re-login successful. Retrying API call...');
                        $detail = $this->fetchDetail($stockOut->ivt_id);

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

    private function fetchDetail(string $ivtId): ?array
    {
        $response = Http::withHeaders($this->ivtHeaders())
            ->get("{$this->baseUrl}/api/main/v3/service/stock-out", [
                'id' => $ivtId,
            ]);

        if ($response->successful()) {
            return $response->json('data');
        }

        $this->warn("API error for id={$ivtId}: HTTP {$response->status()} — {$response->body()}");
        return null;
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

        $this->error('IVT Login response: ' . $response->body());
        return null;
    }

    private function ivtHeaders(): array
    {
        return [
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
            'user-token'                  => $this->userToken,
            'Referer'                     => 'https://ivt.ipos.vn/',
            'Access-Control-Allow-Origin' => 'https://apiivt.ipos.vn',
        ];
    }
}
