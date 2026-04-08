<?php

namespace App\Console\Commands;

use App\Models\Sale;
use Illuminate\Console\Command;

class FixCreatedAt extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fix-created-at';

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
        Sale::chunk(1000, static function ($sales) {
            foreach ($sales as $sale) {
                $sale->created_at = date('Y-m-d H:i:s', $sale->tran_date / 1000);
                $sale->updated_at = date('Y-m-d H:i:s', $sale->tran_date / 1000);
                $sale->save();
            }
        });
    }
}
