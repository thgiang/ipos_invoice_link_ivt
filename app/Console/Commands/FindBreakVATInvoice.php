<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FindBreakVATInvoice extends Command
{
    protected $signature = 'app:find-break-vat-invoice {ky_hieu?}';

    protected $description = 'Find gaps in VAT invoice number sequences from Dem_hoa_don_thue_*.txt files';

    public function handle(): int
    {
        $ignores = array(
            '00005105' => 'LH - Đơn Shopee bị hủy nhưng trót xuất VAT.',
            '00005873' => 'LH - Bị xuất gộp 50 đơn ngày 27/02',
            '00004530' => 'LTT - Đơn Shopee bị hủy nhưng trót xuất VAT',
            '00006837' => 'LTT - Đơn Shopee bị hủy nhưng trót xuất VAT',
            '00008475' => 'LTT - Đơn Shopee bị hủy nhưng trót xuất VAT',
            '00005022' => 'KTD - Đơn Shopee bị hủy nhưng trót xuất VAT',
            '00006617' => 'KTD - Đơn Shopee bị hủy nhưng trót xuất VAT',
            '00004741' => 'KTD - Hóa đơn điều chỉnh thông tin cho hóa đơn 00004179 nên ko cần ghi nhận doanh thu và nguyên liệu',
            '00004579' => 'KTD - Đơn Shopee bị hủy nhưng trót xuất VAT',
            '00003707' => 'GVM - Đơn Shopee bị hủy nhưng trót xuất VAT',
            '00004954' => 'GVM - Đơn Shopee bị hủy nhưng trót xuất VAT',
            '00006251' => 'GVM - Đơn Shopee bị hủy nhưng trót xuất VAT',
        );

        $txtDir = storage_path('app/excel-ivt');

        $kyHieu = $this->argument('ky_hieu');

        if ($kyHieu) {
            $files = ["{$txtDir}/Dem_hoa_don_thue_{$kyHieu}.txt"];
            if (! file_exists($files[0])) {
                $this->error("File not found: {$files[0]}");
                return self::FAILURE;
            }
        } else {
            $files = glob("{$txtDir}/Dem_hoa_don_thue_*.txt");
            if (empty($files)) {
                $this->warn("No Dem_hoa_don_thue_*.txt files found in: {$txtDir}");
                return self::SUCCESS;
            }
        }

        $totalGaps = 0;

        foreach ($files as $filePath) {
            $fileName = basename($filePath);
            $this->line("--- Checking: {$fileName} ---");

            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_map('trim', $lines);
            $lines = array_filter($lines, fn ($l) => $l !== '');

            if (empty($lines)) {
                $this->warn("  File is empty, skipping.");
                continue;
            }

            // Extract numeric part (trailing digits) for comparison
            $numbers = [];
            foreach ($lines as $line) {
                if (! preg_match('/(\d+)$/', $line, $m)) {
                    $this->warn("  Cannot parse invoice number: '{$line}', skipping.");
                    continue;
                }
                $numbers[] = ['raw' => $line, 'value' => (int) $m[1]];
            }

            // Sort by numeric value
            usort($numbers, fn ($a, $b) => $a['value'] <=> $b['value']);

            $gaps = [];
            for ($i = 1; $i < count($numbers); $i++) {
                $prev = $numbers[$i - 1];
                $curr = $numbers[$i];
                $diff = $curr['value'] - $prev['value'];

                if ($diff === 0) {
                    if (!isset($ignores[$curr['raw']])) {
                        $gaps[] = "  [Xuất gộp chung VAT] {$curr['raw']}";
                    }
                } elseif ($diff > 1) {
                    $missing = [];
                    for ($m = $prev['value'] + 1; $m < $curr['value']; $m++) {
                        $missing[] = str_pad((string) $m, strlen($prev['raw']), '0', STR_PAD_LEFT);
                    }
                    $missingStr = implode(', ', $missing);

                    if (!isset($ignores[$missingStr])) {
                        $gaps[] = "  [HÓA ĐƠN KHÔNG LIÊN TỤC] Sau mã {$prev['raw']} → bị nhảy đến {$curr['raw']} — thiếu: {$missingStr}";
                    }
                }
            }

            if (empty($gaps)) {
                $this->info("  OK — {$numbers[0]['raw']} to {$numbers[count($numbers)-1]['raw']} ({$this->count($numbers)} invoices, no gaps)");
            } else {
                $this->error("  Có " . count($gaps) . " vấn đề:");
                foreach ($gaps as $gap) {
                    $this->line($gap);
                }
                $totalGaps += count($gaps);
            }
        }

        $this->newLine();
        if ($totalGaps === 0) {
            $this->info('All invoice sequences are continuous. No gaps found.');
        } else {
            $this->error("Total issues found: {$totalGaps}");
        }

        return $totalGaps === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function count(array $arr): int
    {
        return count($arr);
    }
}
