<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Dựng định mức tiêu hao thực tế cho từng món, từ dữ liệu đã nạp vào MySQL.
 *
 * Công thức trong `RecipeService` là định mức lý tưởng; thực tế có hỏng, hủy,
 * hao nên lượng dùng dao động. Thay vì một con số cứng, đo phân bố thật rồi
 * báo một KHOẢNG.
 *
 * Mẫu sạch nhất là đơn **một cốc, một dòng món**: toàn bộ nguyên liệu trên
 * phiếu xuất thuộc về đúng món ấy, không phải chia chác gì.
 *
 * Ba tầng tách riêng:
 *  1. Định mức LÕI     — đơn không có topping nào ngoài lựa chọn size.
 *  2. Định mức TOPPING — phần tăng thêm so với chính món đó khi bán trần.
 *  3. Định mức BAO BÌ  — theo số cốc cả đơn, không thuộc món nào.
 *
 * Khoảng lấy P10–P90 chứ không min–max: một hai phiếu nhập nhầm kéo min/max đi
 * rất xa. Min/max vẫn in ra để nhìn thấy đuôi.
 *
 * Số lượng là số SAU chỉnh sửa (khách chốt coi bản đã sửa là đúng). Hệ số nhân
 * mỗi tháng một khác nên có thêm cột trung vị từng tháng — khoảng nào rộng bất
 * thường thì nhìn cột đó là biết do tháng nào.
 */
class BuildDinhMuc extends Command
{
    protected $signature = 'app:build-dinh-muc
                            {--min=20 : Số đơn mẫu tối thiểu để một nhóm được coi là đủ tin}
                            {--out=storage/Dinh_muc_nguyen_lieu.xlsx}';

    protected $description = 'Dựng bộ định mức nguyên liệu theo món, topping và số cốc';

    /** Bao bì đi theo cả đơn chứ không theo món — đo riêng theo số cốc. */
    public const PACKAGING = ['TUI2', 'TUI4', 'TUINILONG', 'TUIBONGDOI', 'TUIBONGDON', 'TUIRAC',
        'DAYCOI', 'KHAYGIAY', 'KHAYGIAY4', 'THUNG8'];

    /**
     * Mảng `toppings` của Fabi chứa cả tùy chọn lẫn topping ăn được — 69 tên
     * khác nhau, mà bốn tên nhiều nhất là "Túi nilon", "Size M", "Cốc tai mèo",
     * "Đá chung". Lọc theo mẫu dưới đây thì còn lại đúng topping thật.
     *
     * Vài chỗ dễ sai đã kiểm tay:
     *  - "Phủ socola giòn (cần 100% đá)" là topping THẬT, nên mẫu đá phải neo
     *    đầu chuỗi chứ không bắt "đá" ở giữa.
     *  - "Có sẵn trân châu đen" nghĩa là món vốn đã có, không phải thêm.
     *  - "Có thạch", "Có hạt nổ yến mạch" thì lại là thêm thật.
     */
    private const OPTION_PATTERNS = [
        '/^size /iu', '/đường/iu', '/^đá\b/iu', '/cốc vơi/iu', '/^túi /iu', '/^cốc /iu',
        '/^không /iu', '/^có sẵn /iu', '/^đóng topping riêng/iu', '/^giảm /iu', '/^tăng /iu',
    ];

    /** @var array<string, array{ten: string, dvt: string}> */
    private array $units = [];

    public function handle(): int
    {
        $this->units = json_decode(
            (string) file_get_contents(base_path('storage/app/misa/item_units.json')), true) ?: [];

        $samples = $this->collect();

        if ($samples === []) {
            $this->error('Khong co don mau nao — da nap du lieu chua?');

            return self::FAILURE;
        }

        $this->line('  Don mau mot coc mot mon : '.number_format(count($samples)));

        $plain = array_filter($samples, fn (array $s): bool => $s['tp'] === []);
        $this->line('  Trong do khong topping  : '.number_format(count($plain)));

        $min = (int) $this->option('min');
        [$core, $thin] = $this->coreNorms($plain, $min);
        $topping = $this->toppingNorms($samples, $plain, $min);
        $packaging = $this->packagingNorms($min);

        $formal = $this->formalNorms($core);
        $lech = $this->anomalies($core);

        $this->write($core, $topping, $packaging, $thin, $formal, $lech);

        return self::SUCCESS;
    }

    /**
     * Bảng định mức để ban hành: mỗi món × nguyên liệu một khoảng TỪ – ĐẾN.
     *
     * Khoảng lấy từ **trung vị của từng tháng**, không lấy từ từng đơn: trong
     * một tháng thì mọi đơn cùng món ra đúng một con số (phiếu xuất do máy sinh
     * từ công thức), nên thứ dao động thật là mức của từng tháng.
     *
     * Cận dưới bo xuống, cận trên bo lên theo bước tròn cho dễ đọc — trừ khi
     * sáu tháng cùng một giá trị thì để nguyên, bo ra chỉ làm sai.
     *
     * @param  array<int, array<int, mixed>>  $core
     * @return array<int, array<int, mixed>>
     */
    private function formalNorms(array $core): array
    {
        $rows = [];

        foreach ($core as $line) {
            $monthly = array_values(array_filter(array_slice($line, 13, 6), fn ($v): bool => $v !== null));

            if ($monthly === []) {
                continue;
            }

            $low = min($monthly);
            $high = max($monthly);
            $rows[] = [
                $line[0], $line[1], $line[3], $line[4], $line[5],
                $this->niceFloor((float) $low), $this->niceCeil((float) $high),
                round((float) $line[6], 4),
                count($monthly),
                $line[2],
                $low < $high ? sprintf('mức tháng %s – %s', $this->pretty((float) $low), $this->pretty((float) $high)) : 'sáu tháng như nhau',
            ];
        }

        return $rows;
    }

    /**
     * Soát xem món nào ở tháng nào lệch khỏi mặt bằng.
     *
     * Trục so sánh phải là **mã nguyên liệu**, không phải món. Hệ số nhân gắn
     * với mã: `BOTSUA` đổi mức ở tháng 6 thì mọi món dùng bột sữa đều đổi theo,
     * còn `NHAIXANH` giữ nguyên cả sáu tháng. So các nguyên liệu trong cùng một
     * món với nhau là sai trục — lần chạy đầu gắn cờ 698/822 dòng vì lẽ đó.
     *
     * Nên: với mỗi (mã, tháng), mức kỳ vọng là trung vị hệ số của **mọi món**
     * dùng mã đó. Món nào lệch khỏi mức ấy mới là bất thường thật — đúng kiểu
     * lỗi `KIEUMACH` chạy ×1,90 trong khi bảng ghi ×1,50.
     *
     * Mốc nền của mỗi dòng là tháng thấp nhất của chính nó.
     *
     * @param  array<int, array<int, mixed>>  $core
     * @return array<int, array<int, mixed>>
     */
    private function anomalies(array $core): array
    {
        $series = [];

        foreach ($core as $index => $line) {
            $months = [];

            foreach (array_slice($line, 13, 6) as $offset => $value) {
                if ($value !== null && (float) $value > 0) {
                    $months[$offset + 1] = (float) $value;
                }
            }

            if ($months !== []) {
                $series[$index] = $months;
            }
        }

        // Dạng hệ số theo tháng của MỖI MÃ, dựng từ những dòng đủ 5–6 tháng.
        // Dòng thiếu tháng thì mốc nền của nó rơi vào tháng khác, lấy vào đây
        // là làm lệch cả dạng — `Trà Sữa Sốt Hạt Dẻ` ngừng bán từ tháng 6 nên
        // từng bị chính lỗi này gắn cờ oan.
        $shape = [];

        foreach ($series as $index => $months) {
            if (count($months) < 5) {
                continue;
            }

            $base = min($months);

            foreach ($months as $month => $value) {
                $shape[$core[$index][3]][$month][] = $value / $base;
            }
        }

        foreach ($shape as $code => $months) {
            foreach ($months as $month => $values) {
                if (count($values) < 3) {
                    unset($shape[$code][$month]);

                    continue;
                }

                sort($values);
                $shape[$code][$month] = $this->percentile($values, 0.50);
            }
        }

        $rows = [];

        foreach ($series as $index => $months) {
            $line = $core[$index];
            $code = (string) $line[3];

            // Quy mỗi tháng về "mức nền suy ra": value / hệ số chuẩn của tháng.
            // Nếu dòng này chạy đúng dạng chung thì sáu con số phải bằng nhau,
            // bất kể nó thiếu tháng nào.
            $implied = [];

            foreach ($months as $month => $value) {
                $factor = $shape[$code][$month] ?? null;

                if (is_float($factor) && $factor > 0) {
                    $implied[$month] = $value / $factor;
                }
            }

            if (count($implied) < 2) {
                continue;
            }

            $sorted = array_values($implied);
            sort($sorted);
            $reference = $this->percentile($sorted, 0.50);

            if ($reference < 0.5) {
                // Lượng quá nhỏ thì một chữ số thập phân đã thành "gấp đôi":
                // TINHDAUKHOAI nền 0,1 g lên 0,2 g không phải bất thường.
                continue;
            }

            foreach ($implied as $month => $value) {
                if (abs($value / $reference - 1) <= 0.25) {
                    continue;
                }

                $expected = $reference * $shape[$code][$month];

                $rows[] = [$line[0], $line[1], $month, $code, $line[4], $line[5],
                    $this->pretty($expected), $this->pretty($months[$month]),
                    round($months[$month] / $reference, 2), round((float) $shape[$code][$month], 2),
                    $this->pretty($months[$month] - $expected),
                    sprintf('tháng %d đáng lẽ %s (mức nền %s × hệ số chuẩn %.2f) nhưng đang là %s — lệch %+.0f%%',
                        $month, $this->pretty($expected), $this->pretty($reference),
                        $shape[$code][$month], $this->pretty($months[$month]),
                        100 * ($value / $reference - 1))];
            }
        }

        usort($rows, fn (array $a, array $b): int => [abs((float) $b[10]), $b[8]] <=> [abs((float) $a[10]), $a[8]]);

        return $rows;
    }

    private function niceFloor(float $value): float
    {
        $step = $this->step($value);

        return max($step, floor($value / $step) * $step);
    }

    private function niceCeil(float $value): float
    {
        $step = $this->step($value);

        return ceil($value / $step) * $step;
    }

    private function step(float $value): float
    {
        return match (true) {
            $value >= 100 => 10.0,
            $value >= 20 => 5.0,
            $value >= 5 => 1.0,
            $value >= 2 => 0.5,
            default => 0.1,
        };
    }

    /**
     * Đơn một cốc một dòng món, đã nối được với phiếu xuất.
     *
     * @return array<string, array{mon: string, size: string, tp: array<int, string>, thang: int, nl: array<string, float>}>
     */
    private function collect(): array
    {
        $rows = DB::table('fabi_orders as o')
            ->join('fabi_order_items as i', 'i.tran_id', '=', 'o.tran_id')
            ->whereNotNull('o.misa_refno')
            ->where('o.cup_count', 1)
            ->where('o.item_count', 1)
            ->where('i.quantity', 1)
            ->where('i.item_class_id', 'DU')
            ->get(['o.misa_refno', 'o.order_date', 'i.item_name', 'i.is_size_l', 'i.toppings']);

        $samples = [];

        foreach ($rows as $row) {
            $toppings = [];

            foreach (json_decode((string) $row->toppings, true) ?: [] as $topping) {
                $name = trim((string) ($topping['t'] ?? ''));

                if ($name !== '' && ! $this->isOption($name)) {
                    $toppings[] = $name;
                }
            }

            sort($toppings);

            $samples[$row->misa_refno] = [
                'mon' => (string) $row->item_name,
                'size' => $row->is_size_l ? 'L' : 'M',
                'tp' => $toppings,
                'thang' => (int) substr((string) $row->order_date, 5, 2),
                'nl' => [],
            ];
        }

        foreach (array_chunk(array_keys($samples), 2000) as $chunk) {
            foreach (DB::table('misa_voucher_items')->whereIn('refno', $chunk)
                ->get(['refno', 'item_code', 'quantity']) as $line) {
                $samples[$line->refno]['nl'][$line->item_code] = (float) $line->quantity;
            }
        }

        return array_filter($samples, fn (array $s): bool => $s['nl'] !== []);
    }

    /**
     * Định mức lõi: đơn một cốc, không topping nào ngoài size.
     *
     * @param  array<string, array<string, mixed>>  $plain
     * @return array{0: array<int, array<int, mixed>>, 1: array<int, array<int, mixed>>}
     */
    private function coreNorms(array $plain, int $min): array
    {
        $grouped = [];

        foreach ($plain as $sample) {
            $grouped[$sample['mon'].'|'.$sample['size']][] = $sample;
        }

        $rows = [];
        $thin = [];

        foreach ($grouped as $key => $group) {
            [$drink, $size] = explode('|', $key);
            $total = count($group);
            $byCode = [];
            $byMonth = [];

            foreach ($group as $sample) {
                foreach ($sample['nl'] as $code => $qty) {
                    $byCode[$code][] = $qty;
                    $byMonth[$code][$sample['thang']][] = $qty;
                }
            }

            foreach ($byCode as $code => $values) {
                if (in_array($code, self::PACKAGING, true)) {
                    continue;
                }

                $seen = count($values);

                if ($seen < 3 || $seen / $total < 0.02) {
                    continue;
                }

                sort($values);
                $line = [$drink, $size, $total, $code,
                    (string) ($this->units[$code]['ten'] ?? ''), (string) ($this->units[$code]['dvt'] ?? ''),
                    round($seen / $total, 4),
                    $this->pretty($this->percentile($values, 0.10)),
                    $this->pretty($this->percentile($values, 0.90)),
                    $this->pretty($this->percentile($values, 0.50)),
                    $this->pretty($values[0]), $this->pretty($values[$seen - 1]), $seen];

                for ($month = 1; $month <= 6; $month++) {
                    $monthly = $byMonth[$code][$month] ?? [];
                    sort($monthly);
                    $line[] = $monthly === [] ? null : $this->pretty($this->percentile($monthly, 0.50));
                }

                if ($total >= $min) {
                    $rows[] = $line;
                } else {
                    $thin[] = $line;
                }
            }
        }

        $sort = fn (array $a, array $b): int => [$a[0], $a[1], -$a[6]] <=> [$b[0], $b[1], -$b[6]];
        usort($rows, $sort);
        usort($thin, $sort);

        return [$rows, $thin];
    }

    /**
     * Định mức của một topping = lượng dùng khi CÓ nó, trừ mức nền của chính
     * món đó khi bán trần. Chỉ lấy đơn có đúng MỘT topping — nhiều topping thì
     * không biết phần chênh thuộc về cái nào.
     *
     * @param  array<string, array<string, mixed>>  $samples
     * @param  array<string, array<string, mixed>>  $plain
     * @return array<int, array<int, mixed>>
     */
    private function toppingNorms(array $samples, array $plain, int $min): array
    {
        $baseline = [];

        foreach ($plain as $sample) {
            foreach ($sample['nl'] as $code => $qty) {
                $baseline[$sample['mon'].'|'.$sample['size']][$code][] = $qty;
            }
        }

        foreach ($baseline as $key => $codes) {
            foreach ($codes as $code => $values) {
                sort($values);
                $baseline[$key][$code] = $this->percentile($values, 0.50);
            }
        }

        $diff = [];
        $count = [];

        foreach ($samples as $sample) {
            if (count($sample['tp']) !== 1) {
                continue;
            }

            $key = $sample['mon'].'|'.$sample['size'];

            if (! isset($baseline[$key])) {
                continue;   // món chưa từng bán trần thì không có mức nền để trừ
            }

            $name = $sample['tp'][0];
            $count[$name] = ($count[$name] ?? 0) + 1;

            foreach ($sample['nl'] as $code => $qty) {
                if (in_array($code, self::PACKAGING, true)) {
                    continue;
                }

                $delta = $qty - (float) ($baseline[$key][$code] ?? 0);

                // Chỉ giữ phần TĂNG: topping thêm nguyên liệu chứ không bớt.
                if ($delta > 0.0001) {
                    $diff[$name][$code][] = $delta;
                }
            }
        }

        $rows = [];

        foreach ($diff as $name => $codes) {
            $total = $count[$name] ?? 0;

            if ($total < $min) {
                continue;
            }

            foreach ($codes as $code => $values) {
                $seen = count($values);

                // Dưới ngưỡng này là dao động của món nền, không phải topping.
                if ($seen / $total < 0.25) {
                    continue;
                }

                sort($values);
                $rows[] = [$name, $total, $code,
                    (string) ($this->units[$code]['ten'] ?? ''), (string) ($this->units[$code]['dvt'] ?? ''),
                    round($seen / $total, 4),
                    $this->pretty($this->percentile($values, 0.10)),
                    $this->pretty($this->percentile($values, 0.90)),
                    $this->pretty($this->percentile($values, 0.50)), $seen];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a[0], -$a[5]] <=> [$b[0], -$b[5]]);

        return $rows;
    }

    /**
     * Bao bì theo SỐ CỐC của cả đơn.
     *
     * Loại `NHANVIENDUNG` và `HUYDO`: hai nguồn này không xuất bao bì gì cả
     * (`RecipeService::tinhCocNapThiaTui` trả về rỗng), để lẫn vào là kéo tụt
     * định mức xuống.
     *
     * @return array<int, array<int, mixed>>
     */
    private function packagingNorms(int $min): array
    {
        $orders = DB::table('fabi_orders')
            ->whereNotNull('misa_refno')
            ->whereNotIn('source_deli', ['NHANVIENDUNG', 'HUYDO'])
            ->whereBetween('cup_count', [1, 10])
            ->get(['misa_refno', 'cup_count'])
            ->keyBy('misa_refno');

        $totals = [];

        foreach ($orders as $order) {
            $totals[$order->cup_count] = ($totals[$order->cup_count] ?? 0) + 1;
        }

        $byCups = [];

        foreach (array_chunk($orders->keys()->all(), 2000) as $chunk) {
            foreach (DB::table('misa_voucher_items')->whereIn('refno', $chunk)
                ->whereIn('item_code', self::PACKAGING)
                ->get(['refno', 'item_code', 'quantity']) as $line) {
                $byCups[$orders[$line->refno]->cup_count][$line->item_code][] = (float) $line->quantity;
            }
        }

        ksort($byCups);
        $rows = [];

        foreach ($byCups as $cups => $codes) {
            $total = $totals[$cups] ?? 0;

            if ($total < $min) {
                continue;
            }

            ksort($codes);

            foreach ($codes as $code => $values) {
                $seen = count($values);

                if ($seen / $total < 0.02) {
                    continue;
                }

                sort($values);
                $rows[] = [$cups, $total, $code,
                    (string) ($this->units[$code]['ten'] ?? ''),
                    round($seen / $total, 4),
                    $this->pretty($this->percentile($values, 0.10)),
                    $this->pretty($this->percentile($values, 0.90)),
                    $this->pretty($this->percentile($values, 0.50)), $seen];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, mixed>>  $core
     * @param  array<int, array<int, mixed>>  $topping
     * @param  array<int, array<int, mixed>>  $packaging
     * @param  array<int, array<int, mixed>>  $thin
     */
    private function write(array $core, array $topping, array $packaging, array $thin,
        array $formal, array $lech): void
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $this->cover($book, $formal, $lech);

        $coreHeader = ['Món', 'Size', 'Số đơn mẫu', 'Mã NVL', 'Tên nguyên liệu', 'ĐVT', 'Tần suất',
            'Định mức TỪ', 'Định mức ĐẾN', 'Trung vị', 'Nhỏ nhất', 'Lớn nhất', 'Số đơn có',
            'T1', 'T2', 'T3', 'T4', 'T5', 'T6'];

        $this->sheet($book, 'BAN DINH MUC',
            ['Món', 'Size', 'Mã NVL', 'Tên nguyên liệu', 'ĐVT', 'Định mức TỪ', 'Định mức ĐẾN',
                'Tần suất', 'Số tháng có', 'Số đơn mẫu', 'Ghi chú'],
            $formal, 'H', 'F:G', 'C2');

        $this->sheet($book, 'Kiem tra lech',
            ['Món', 'Size', 'Tháng', 'Mã NVL', 'Tên nguyên liệu', 'ĐVT', 'Đáng lẽ là', 'Đang là',
                'Hệ số thực tế', 'Hệ số chuẩn của mã', 'Chênh mỗi cốc', 'Vấn đề'],
            $lech, 'I', 'G:H', 'D2');

        $this->sheet($book, 'Dinh muc mon', $coreHeader, $core, 'G', 'H:L', 'D2');
        $this->sheet($book, 'Dinh muc topping',
            ['Topping', 'Số đơn mẫu', 'Mã NVL', 'Tên nguyên liệu', 'ĐVT', 'Tần suất',
                'Định mức TỪ', 'Định mức ĐẾN', 'Trung vị', 'Số đơn có'],
            $topping, 'F', 'G:I', 'C2');
        $this->sheet($book, 'Bao bi theo so coc',
            ['Số cốc', 'Số đơn mẫu', 'Mã bao bì', 'Tên', 'Tần suất',
                'Định mức TỪ', 'Định mức ĐẾN', 'Trung vị', 'Số đơn có'],
            $packaging, 'E', 'F:H', 'C2');

        if ($thin !== []) {
            $this->sheet($book, 'It mau', $coreHeader, $thin, 'G', 'H:L', 'D2');
        }

        $book->setActiveSheetIndex(0);
        $out = base_path((string) $this->option('out'));
        (new XlsxWriter($book))->save($out);

        $this->newLine();
        $this->line('  Ban dinh muc     : '.number_format(count($formal)).' dong');
        $this->line('  Kiem tra lech    : '.number_format(count($lech)).' dong canh bao');
        $this->line('  Dinh muc mon     : '.number_format(count($core)).' dong');
        $this->line('  Dinh muc topping : '.number_format(count($topping)).' dong');
        $this->line('  Bao bi           : '.number_format(count($packaging)).' dong');
        $this->line('  It mau           : '.number_format(count($thin)).' dong');
        $this->line('  Da ghi '.$out);
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function sheet(Spreadsheet $book, string $title, array $header, array $rows,
        string $percentColumn, string $numberRange, string $freeze): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($header, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $last = count($rows) + 1;
        $lastColumn = chr(ord('A') + count($header) - 1);

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        [$numberFrom, $numberTo] = explode(':', $numberRange);
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DDEBF7');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $sheet->getStyle($percentColumn.'2:'.$percentColumn.$last)->getNumberFormat()->setFormatCode('0%');
        $sheet->getStyle($numberFrom.'2:'.$numberTo.$last)->getNumberFormat()->setFormatCode('#,##0.##');
        $sheet->getStyle('A1:'.$lastColumn.$last)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane($freeze);

        // Tô nguyên liệu lõi (có mặt >= 90% số đơn) để tách khỏi thứ chỉ thỉnh thoảng có.
        for ($row = 2; $row <= $last; $row++) {
            if ((float) $sheet->getCell($percentColumn.$row)->getValue() >= 0.9) {
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8F3E8');
            }
        }
    }

    /**
     * Trang đầu: nói rõ văn bản này dựng từ đâu và đọc thế nào.
     *
     * Bảng định mức mà không kèm phạm vi và cách dựng thì người đọc sau sẽ tự
     * suy ra một cách hiểu khác — nhất là chỗ "vì sao khoảng lại rộng".
     *
     * @param  array<int, array<int, mixed>>  $formal
     * @param  array<int, array<int, mixed>>  $lech
     */
    private function cover(Spreadsheet $book, array $formal, array $lech): void
    {
        $drinks = count(array_unique(array_map(fn (array $r): string => $r[0].'|'.$r[1], $formal)));

        $lines = [
            ['BẢNG ĐỊNH MỨC TIÊU HAO NGUYÊN VẬT LIỆU'],
            ['Kỳ áp dụng: tháng 01/2026 – tháng 06/2026'],
            [''],
            ['1. PHẠM VI'],
            ['Bảng này quy định lượng nguyên vật liệu tiêu hao cho mỗi đơn vị sản phẩm bán ra,'],
            [sprintf('gồm %d nhóm món × size và %s dòng định mức.', $drinks, number_format(count($formal)))],
            [''],
            ['2. CƠ SỞ DỰNG SỐ'],
            ['Đối chiếu từng đơn hàng bán ra với phiếu xuất kho tương ứng (nối theo số hóa đơn VAT).'],
            ['Chỉ lấy đơn MỘT CỐC, MỘT MÓN và không có topping — khi đó toàn bộ nguyên liệu trên'],
            ['phiếu xuất thuộc về đúng món đó, không phải phân bổ.'],
            ['Định mức topping và định mức bao bì tách thành hai bảng riêng.'],
            [''],
            ['3. VÌ SAO LÀ MỘT KHOẢNG, KHÔNG PHẢI MỘT SỐ'],
            ['Đơn không xuất hóa đơn (hàng hủy, nhân viên dùng, đơn 0 đồng) không phát sinh phiếu'],
            ['xuất riêng, nên phần tiêu hao của chúng được gánh vào các đơn có hóa đơn. Cùng một'],
            ['món vì thế ghi nhận ở nhiều mức khác nhau tùy kỳ. Cột "Định mức TỪ – ĐẾN" là biên'],
            ['dưới và biên trên của các mức đã ghi nhận trong sáu tháng.'],
            [''],
            ['4. CÁCH ĐỌC'],
            ['Cột "Tần suất" cho biết nguyên liệu đó có mặt ở bao nhiêu phần trăm số đơn:'],
            ['gần 100% là nguyên liệu lõi; thấp hơn nhiều là nguyên liệu thay thế hoặc tùy chọn.'],
            ['Dòng tô xanh là nguyên liệu lõi.'],
            [''],
            ['5. KIỂM TRA LỆCH ĐỊNH MỨC'],
            [sprintf('Sheet "Kiem tra lech" liệt kê %d trường hợp món × tháng có mức ghi nhận lệch quá 25%%',
                count($lech))],
            ['so với mặt bằng của chính nguyên liệu đó ở các món khác trong cùng tháng.'],
            ['Riêng đường nước (THUNGDUONG) vốn dao động theo lựa chọn 0–120% đường của khách,'],
            ['nên cảnh báo ở mã này là bằng chứng yếu, cần xem thêm trước khi kết luận.'],
            [''],
            ['6. CÁC SHEET'],
            ['BAN DINH MUC        — bảng định mức chính thức, dùng để ban hành'],
            ['Kiem tra lech       — các trường hợp lệch cần rà lại'],
            ['Dinh muc mon        — số liệu chi tiết kèm trung vị từng tháng (căn cứ của bảng chính)'],
            ['Dinh muc topping    — định mức cho từng topping, tính riêng'],
            ['Bao bi theo so coc  — bao bì theo số cốc của cả đơn, không thuộc món nào'],
            ['It mau              — nhóm dưới ngưỡng mẫu, chỉ để tham khảo'],
        ];

        $sheet = $book->createSheet();
        $sheet->setTitle('Van ban');
        $sheet->fromArray($lines, null, 'A1');
        $sheet->getColumnDimension('A')->setWidth(105);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setItalic(true);

        foreach ([4, 8, 14, 20, 25, 31] as $row) {
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
        }
    }

    /** Tùy chọn (đá, đường, size, bao bì) chứ không phải topping ăn được. */
    private function isOption(string $name): bool
    {
        foreach (self::OPTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Phân vị nội suy tuyến tính — dãy phải đã sắp xếp. */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);

        if ($n === 1) {
            return (float) $sorted[0];
        }

        $position = $p * ($n - 1);
        $low = (int) floor($position);
        $high = (int) ceil($position);

        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($position - $low);
    }

    /** Làm tròn để đọc: số nhỏ giữ nhiều chữ số hơn. */
    private function pretty(float $value): float
    {
        if ($value >= 100) {
            return round($value);
        }

        return $value >= 10 ? round($value, 1) : round($value, 2);
    }
}
