<?php

namespace App\Console\Commands;

use App\Models\StockInSummary;
use App\Models\StockOutSummary;
use App\Services\RecipeCatalog;
use App\Services\TaxUnitCatalog;
use App\Services\UnitConversionCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class DoiSoat152 extends Command
{
    protected $signature = 'app:doi-soat-152
                            {--file= : Sổ thuế .xlsx. Mặc định: bản chính thức trong storage/}
                            {--sheet= : Tên sheet chứa báo cáo TK 152}
                            {--start_date=2026-01-01 : Start of the period (Y-m-d)}
                            {--end_date=2026-06-30 : End of the period (Y-m-d)}
                            {--gi_types= : Xuất kho được CỘNG (vd 1,6). Mặc định: mọi loại trừ 3 (điều chuyển)}
                            {--gi_minus= : Xuất kho bị TRỪ (vd 2,4,5 — hủy, hao hụt, điều chỉnh)}
                            {--gr_plus= : Nhập kho được CỘNG (vd 2,5 — nhập điều chỉnh, nhập khác)}
                            {--with_processed : Tính cả bán thành phẩm (mặc định chỉ lấy NVL thô)}
                            {--warehouses= : Danh sách kho được cộng, cách nhau bởi dấu phẩy. Mặc định: 4 cửa hàng}
                            {--suffix= : Hậu tố tên file xuất ra}';

    protected $description = 'Đối soát tồn kho TK 152 (sổ thuế) với tổng xuất kho 4 cửa hàng trong IVT';

    /**
     * The four shop warehouses. Sheet 152 is labelled "Bếp Trung Liệt" but is
     * in fact the total of these four, so the IVT side must sum exactly them —
     * the two internal warehouses (Thái Hà, Trung Liệt) are excluded to avoid
     * double counting goods that merely transit through them.
     */
    private const STORE_WAREHOUSES = [
        'CHLANGHA',
        'CHGIANGVANMINH',
        'CHLETRONGTAN',
        'CHKHUCTHUADU',
    ];

    /**
     * Internal warehouses. They do not sell: they process raw materials and
     * ship the result to the four shops.
     */
    private const INTERNAL_WAREHOUSES = ['BTRUNGLIET', 'KTHAIHA'];

    /**
     * XNB — an internal transfer moves goods between warehouses without
     * consuming anything. Counting it alongside the shop sale of the very same
     * goods would bill one physical item twice, so it is excluded from every
     * consumption figure and only reported as a separate reference column.
     */
    private const GI_TYPE_TRANSFER = 3;

    /**
     * XSD — raw materials the kitchen burns to make a semi-finished good.
     */
    private const GI_TYPE_PROCESSING = 6;

    /**
     * Manual overrides for items whose conversion the IVT catalog cannot
     * resolve, because the accountant's unit label appears nowhere in that
     * item's own conversion graph.
     *
     *   item_id => số đơn vị IVT trong 1 đơn vị của sổ thuế
     *
     * Two ways of writing an entry:
     *
     *   'X' => 2.5                            // 1 đơn vị thuế = 2,5 đơn vị IVT của chính dòng đó
     *   'Y' => ['qty' => 1000, 'unit' => 'ML'] // 1 đơn vị thuế = 1000 ML, rồi tự quy sang đơn vị của dòng
     *
     * The second form is what a customer actually says ("1 hộp = 1000 ml") and
     * is the safer one to write down: the bare number silently means whatever
     * unit the warehouse happens to stock the item in today, so it turns wrong
     * the day IVT switches that item from LIT to ML.
     *
     * Every entry must come from the customer: a packaging unit holds a
     * different amount for every product, so nothing may be inferred here.
     * Items neither resolvable nor listed land in the "Chờ quy đổi" sheet
     * instead of being compared with an invented factor.
     *
     * @var array<string, float|array{qty: float, unit: string}>
     */
    public const ITEM_UNIT_FACTORS = [
        // Khách hàng xác nhận: nhãn hai bên chỉ khác cách gọi, cùng một quy cách
        // đóng gói, nên hệ số bằng 1.
        'HATNOYENMACH' => 1.0,   // Lọ = HOP, 1 HOP = 850 GR
        'TRANCHAUTRANG' => 1.0,  // Gói = TUI, 1 TUI = 1500 GR
        'TUI4' => 1.0,           // Túi = CAI, IVT không khai quy đổi nào
        'TRATHAIXANH' => 1.0,    // Gói (thuế) = TUI (IVT) — khách xác nhận 1 túi = 1 gói

        // Khách hàng sửa lại 2026-08-22. Cả hai mã trước đây khai hệ số 1 —
        // đúng vô tình với BOTTHACH (IVT tồn theo TUI, mà 1 TUI đúng 560 GR)
        // nhưng sai hẳn với BOTCONSOC: sổ thuế đếm theo hộp 10 gói, IVT tồn
        // theo gói lẻ, nên hệ số phải là 10 chứ không phải 1.
        'BOTTHACH' => ['qty' => 560.0, 'unit' => 'GR'],   // 1 Gói = 560 g
        'BOTCONSOC' => ['qty' => 120.0, 'unit' => 'GR'],  // 1 Túi = 1 hộp = 10 gói × 12 g

        // Khách hàng xác nhận 2026-08-22. Ghi theo đúng đơn vị khách nói (ML,
        // GR) rồi để catalog tự quy sang đơn vị IVT đang tồn — IVT tồn ba mã
        // này theo LIT/KG nên hệ số thực tế ra 1 / 0,5 / 1.
        'SUATUOITH' => ['qty' => 1000.0, 'unit' => 'ML'],   // 1 Hộp = 1000 ML
        'MUOISACH' => ['qty' => 500.0, 'unit' => 'GR'],     // 1 Túi = 500 g
        'GELATINEGELITA' => ['qty' => 1000.0, 'unit' => 'GR'], // 1 Hộp = 1 kg
        'MUTMAN' => ['qty' => 2100.0, 'unit' => 'GR'],      // 1 Chai syrup mận = 2100 g
    ];

    /**
     * The hand-confirmed factor for an item, expressed in the unit the caller
     * counts that item in — or null when the customer has not ruled on it.
     *
     * Shared with the report screens so a factor is written down once and both
     * the command and the web page pick it up.
     */
    public static function manualFactor(string $itemId, string $ivtUnit, UnitConversionCatalog $units): ?float
    {
        $entry = self::ITEM_UNIT_FACTORS[$itemId] ?? null;

        if ($entry === null) {
            return null;
        }

        if (! is_array($entry)) {
            return (float) $entry;
        }

        if (UnitConversionCatalog::normaliseUnit($entry['unit']) === UnitConversionCatalog::normaliseUnit($ivtUnit)) {
            return (float) $entry['qty'];
        }

        // "1 hộp = 1000 ML" while the warehouse counts litres: the metric step
        // is the catalog's job, not something to restate by hand here.
        $step = $units->factor($itemId, $entry['unit'], $ivtUnit);

        return $step === null ? null : (float) $entry['qty'] * $step;
    }

    /**
     * Findings the customer has already ruled on, carried into every sheet so
     * a known-and-accepted gap is never re-investigated as if it were new.
     *
     * @var array<string, string>
     */
    private const ITEM_NOTES = [
        'QUAMANGCUT' => 'Giá dao động 50k–120k/kg tùy thời điểm. Cả giá thuế (53.007) lẫn giá IVT (74.851) đều nằm trong khoảng nên không kết luận được bên nào sai.',
        'THITMANGCAU' => 'Giá chuẩn 74k/kg, nhưng khách hàng lưu ý có chênh lệch theo thời điểm và sai lệch hóa đơn.',
        'TRADEN' => 'Giá chuẩn 116k/túi 500g. Sổ thuế ghi theo kg nên giá chuẩn quy về 232k/kg.',
        'CHANH' => 'Khách hàng xác nhận giá đúng là 75.000đ/kg; 9.149đ/kg bên sổ thuế là sai. Sổ thuế ghi 124,794 kg / 1.141.768đ — một trong hai con số sai, chưa rõ cái nào, nên dòng này bị loại khỏi phép tính chênh thay vì tự sửa.',
        'SUATUOITH' => 'NVL của công thức chế biến: mua và dùng tại Bếp, thành phẩm mới chuyển ra 4 cửa hàng — không xuất ở cửa hàng là đúng. Xem cột "cả 6 kho".',
    ];

    /**
     * Rows whose tax-book figures the customer has confirmed to be wrong. They
     * are still shown, but excluded from the variance subtotal: a bad number on
     * the books is a bookkeeping error to fix, not a real gap in consumption.
     * They are not silently corrected either — when a price is wrong it is
     * unknown whether the quantity or the value carries the mistake.
     *
     * @var string[]
     */
    private const TAX_DATA_ERRORS = ['CHANH', 'MUTMAN'];

    /**
     * Đơn giá chuẩn do khách hàng cung cấp, tính theo ĐVT của sổ thuế.
     *
     *   item_id => giá đúng cho 1 đơn vị (ĐVT thuế)
     *
     * Dùng để phân xử khi hai bên lệch giá: bên nào lệch xa giá chuẩn thì bên
     * đó ghi sai. Không tự suy ra giá nào — chỉ điền khi khách hàng xác nhận.
     *
     * @var array<string, float>
     */
    private const CORRECT_PRICES = [
        'CHANH' => 75000.0,        // 75k/kg
        'THITMANGCAU' => 74000.0,  // 74k/kg
        'TRADEN' => 232000.0,      // 116k/túi 500g; sổ thuế ghi theo kg nên x2
        'THUNGDUONG' => 520000.0,  // 520k/can (IVT: "can 25 lít")
    ];

    /**
     * Codes the accountant demonstrably books into one another, so a per-item
     * variance is meaningless for them: only the group total is. Quantities are
     * deliberately not summed — members use different units — the group is
     * judged on value alone, as the customer asked.
     *
     * @var array<string, string[]>
     */
    private const ITEM_GROUPS = [
        'Sữa / kem béo / trà thái' => [
            'TRATHAIXANH', 'SUATUOITH', 'RICHLUN', 'WHIPPINGAVONMORE', 'WHIPPINGCREAM',
        ],
        'Syrup mận / syrup bưởi' => [
            'MUTMAN', 'SYRUPBUOIMONIN',
        ],
    ];

    public function __construct(
        private readonly UnitConversionCatalog $units,
        private readonly RecipeCatalog $recipes,
    ) {
        parent::__construct();
    }

    /**
     * The editable price list: one row per reconciled item, with the confirmed
     * price left blank where the customer has not ruled yet. Filling a cell in
     * and copying it into CORRECT_PRICES is all it takes to attribute that
     * item's price gap to one side or the other.
     *
     * @param  array<int, array<int, mixed>>  $matched
     * @return array<int, array<int, mixed>>
     */
    private function buildPriceList(array $matched): array
    {
        $rows = [];

        foreach ($matched as $m) {
            $code = (string) $m[0];
            $taxPrice = (float) $m[10];
            $ivtPrice = (float) $m[11];
            $gap = $taxPrice != 0.0 ? ($ivtPrice - $taxPrice) / $taxPrice * 100 : null;
            $confirmed = self::CORRECT_PRICES[$code] ?? null;

            $rows[] = [
                $code, (string) $m[1], (string) $m[3],
                $taxPrice, $ivtPrice, $gap,
                (float) $m[23], (float) $m[25],
                $confirmed,
                $confirmed === null ? '' : ($this->closerSide($taxPrice, $ivtPrice, $confirmed)),
                abs($gap ?? 0) >= 50 ? 'Lệch rất lớn' : (abs($gap ?? 0) >= 30 ? 'Lệch lớn' : (abs($gap ?? 0) >= 10 ? 'Lệch vừa' : '')),
                (float) $m[7],
                (float) $m[15],
                (string) $m[22],
            ];
        }

        usort($rows, fn ($x, $y) => abs($y[5] ?? 0) <=> abs($x[5] ?? 0));

        return $rows;
    }

    /**
     * Which book the confirmed price happens to sit closer to. Stated as an
     * observation, not a verdict: the tax book is the trusted price source by
     * default, and a handful of rulings on the most divergent rows is not
     * evidence enough to overturn that.
     */
    private function closerSide(float $taxPrice, float $ivtPrice, float $correct): string
    {
        $taxOff = $correct != 0.0 ? abs($taxPrice - $correct) / $correct * 100 : 0.0;
        $ivtOff = $correct != 0.0 ? abs($ivtPrice - $correct) / $correct * 100 : 0.0;

        return $taxOff <= $ivtOff
            ? sprintf('Giá chuẩn sát sổ thuế (IVT lệch %.1f%%)', $ivtOff)
            : sprintf('Giá chuẩn sát IVT (sổ thuế lệch %.1f%%)', $taxOff);
    }

    /**
     * Split the value gap into the two things that can cause it.
     *
     *   A = giá thuế  × SL thuế   — what the books say
     *   B = giá thuế  × SL IVT    — the books' price on IVT's volume
     *   C = giá IVT   × SL IVT    — what IVT says
     *
     * B - A is therefore pure volume (same price, more or fewer units) and
     * C - B is pure price (same units, valued differently). They add up to the
     * whole gap, so every dong is attributed to one cause or the other.
     *
     * Only the reconciled items qualify: the rest have no comparable price or
     * quantity on both sides.
     *
     * @param  array<int, array<int, mixed>>  $matched
     * @return array<int, array<int, mixed>>
     */
    private function buildPricing(array $matched): array
    {
        $rows = [];

        foreach ($matched as $m) {
            $code = (string) $m[0];
            $taxQty = (float) $m[6];
            $ivtQty = (float) $m[7];
            $taxPrice = (float) $m[10];
            $ivtPrice = (float) $m[11];
            $qtyDiff = $ivtQty - $taxQty;

            // Same quantities, costed twice — once with each side's price list.
            // The quantity gap is the finding; the two price lists just put two
            // different money values on it.
            $taxAtTaxPrice = $taxPrice * $taxQty;
            $ivtAtTaxPrice = $taxPrice * $ivtQty;
            $taxAtIvtPrice = $ivtPrice * $taxQty;
            $ivtAtIvtPrice = $ivtPrice * $ivtQty;

            // Third list: the tax book's own inbound price, which sidesteps the
            // skewed opening balance that corrupts its issue valuation.
            $inPrice = (float) $m[23];
            $taxAtInPrice = $inPrice * $taxQty;
            $ivtAtInPrice = $inPrice * $ivtQty;

            $rows[] = [
                $code, (string) $m[1], (string) $m[3],
                $taxQty, $ivtQty, $qtyDiff,
                $taxQty != 0.0 ? $qtyDiff / $taxQty * 100 : null,

                $taxPrice, $taxAtTaxPrice, $ivtAtTaxPrice,
                $ivtAtTaxPrice - $taxAtTaxPrice,
                $taxAtTaxPrice != 0.0 ? ($ivtAtTaxPrice - $taxAtTaxPrice) / $taxAtTaxPrice * 100 : null,

                $ivtPrice, $taxAtIvtPrice, $ivtAtIvtPrice,
                $ivtAtIvtPrice - $taxAtIvtPrice,
                $taxAtIvtPrice != 0.0 ? ($ivtAtIvtPrice - $taxAtIvtPrice) / $taxAtIvtPrice * 100 : null,

                $inPrice, $taxAtInPrice, $ivtAtInPrice,
                $ivtAtInPrice - $taxAtInPrice,
                $taxAtInPrice != 0.0 ? ($ivtAtInPrice - $taxAtInPrice) / $taxAtInPrice * 100 : null,

                (float) $m[24], (float) $m[25],
                self::CORRECT_PRICES[$code] ?? null,
                (string) $m[22],
            ];
        }

        usort($rows, fn ($x, $y) => abs($y[10]) <=> abs($x[10]));

        $clean = array_values(array_filter(
            $rows,
            fn ($r): bool => ! in_array($r[0], self::TAX_DATA_ERRORS, true)
        ));

        $total = function (string $label, array $set, string $note): array {
            return [
                $label, '', '',
                array_sum(array_column($set, 3)) ? null : null, null,
                null, null,
                null,
                array_sum(array_column($set, 8)),
                array_sum(array_column($set, 9)),
                array_sum(array_column($set, 10)), null,
                null,
                array_sum(array_column($set, 13)),
                array_sum(array_column($set, 14)),
                array_sum(array_column($set, 15)), null,
                null,
                array_sum(array_column($set, 18)),
                array_sum(array_column($set, 19)),
                array_sum(array_column($set, 20)), null,
                null, null, null, $note,
            ];
        };

        $rows[] = $total('TỔNG', $rows, 'Số lượng không cộng được — các mặt hàng khác đơn vị.');
        $rows[] = $total(
            'TỔNG (loại dòng sổ thuế ghi sai)',
            $clean,
            'Đã loại: '.implode(', ', self::TAX_DATA_ERRORS)
        );

        return $rows;
    }

    /**
     * The processed goods left out of the comparison, listed so the exclusion
     * is auditable rather than invisible.
     *
     * @return array<int, array<int, mixed>>
     */
    private function excludedProcessed(string $startDate, string $endDate): array
    {
        $processed = $this->recipes->processedItemIds();

        if ($processed === []) {
            return [];
        }

        return StockOutSummary::query()
            ->whereIn('item_id', $processed)
            ->where('gi_type', '!=', self::GI_TYPE_TRANSFER)
            ->where('period_from', '>=', $startDate)
            ->where('period_to', '<=', $endDate)
            ->selectRaw('item_id,
                         MAX(item_name) AS item_name,
                         MAX(main_unit_id) AS unit,
                         SUM(main_unit_qty) AS qty,
                         SUM(amount_cost) AS cost')
            ->groupBy('item_id')
            ->orderByRaw('SUM(amount_cost) DESC')
            ->get()
            ->map(fn ($r): array => [
                $r->item_id, (string) $r->item_name, (string) $r->unit,
                (float) $r->qty, 'cost' => (float) $r->cost,
            ])
            ->all();
    }

    /**
     * Restrict a query to raw materials.
     *
     * TK 152 is the raw-materials account: not one of the 118 codes in the tax
     * sheet owns a recipe. Anything the kitchen produced belongs to 154/155
     * instead, and its ingredients were already counted when they were issued
     * into processing (XSD), so including it here would count the same
     * material twice.
     */
    private function rawMaterialsOnly(Builder $query): Builder
    {
        if ($this->option('with_processed')) {
            return $query;
        }

        $processed = $this->recipes->processedItemIds();

        return $processed === [] ? $query : $query->whereNotIn('item_id', $processed);
    }

    /**
     * Narrow a query to the stock-out categories asked for. Transfers are the
     * default exclusion because they move goods without consuming them.
     */
    /**
     * Warehouses being summed. Defaults to the four shops, but processing
     * happens at the kitchen too, so a run that counts XSD usually wants
     * Bếp Trung Liệt in scope as well.
     *
     * @return string[]
     */
    private function warehouses(): array
    {
        $requested = trim((string) $this->option('warehouses'));

        if ($requested === '') {
            return self::STORE_WAREHOUSES;
        }

        return array_values(array_filter(array_map('trim', explode(',', $requested))));
    }

    /**
     * @return int[]
     */
    private static function parseTypes(mixed $value): array
    {
        $raw = trim((string) $value);

        return $raw === ''
            ? []
            : array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /**
     * Issues of the given categories, keyed by item.
     *
     * @param  int[]  $types
     * @return array<string, array{qty: float, cost: float}>
     */
    private function readStockOutTypes(string $startDate, string $endDate, array $types): array
    {
        if ($types === []) {
            return [];
        }

        return $this->rawMaterialsOnly(StockOutSummary::query())
            ->whereIn('gi_type', $types)
            ->whereIn('from_warehouse_id', $this->warehouses())
            ->where('period_from', '>=', $startDate)
            ->where('period_to', '<=', $endDate)
            ->selectRaw('item_id, SUM(main_unit_qty) AS qty, SUM(amount_cost) AS cost')
            ->groupBy('item_id')
            ->get()
            ->mapWithKeys(fn ($r): array => [$r->item_id => [
                'qty' => (float) $r->qty,
                'cost' => (float) $r->cost,
            ]])
            ->all();
    }

    /**
     * Receipts of the given categories.
     *
     * @return array<string, array{qty: float, cost: float}>
     */
    private function readStockIn(string $startDate, string $endDate, string $option): array
    {
        $types = self::parseTypes($this->option($option));

        if ($types === []) {
            return [];
        }

        $query = StockInSummary::query()
            ->whereIn('gr_type', $types)
            ->whereIn('to_warehouse_id', $this->warehouses())
            ->where('period_from', '>=', $startDate)
            ->where('period_to', '<=', $endDate);

        if (! $this->option('with_processed')) {
            $processed = $this->recipes->processedItemIds();

            if ($processed !== []) {
                $query->whereNotIn('item_id', $processed);
            }
        }

        return $query
            ->selectRaw('item_id, SUM(main_unit_qty) AS qty, SUM(amount_cost) AS cost')
            ->groupBy('item_id')
            ->get()
            ->mapWithKeys(fn ($r): array => [$r->item_id => [
                'qty' => (float) $r->qty,
                'cost' => (float) $r->cost,
            ]])
            ->all();
    }

    private function forGiTypes(Builder $query): Builder
    {
        $requested = trim((string) $this->option('gi_types'));

        if ($requested === '') {
            return $query->where('gi_type', '!=', self::GI_TYPE_TRANSFER);
        }

        $types = array_values(array_filter(array_map('intval', explode(',', $requested))));

        return $query->whereIn('gi_type', $types);
    }

    public function handle(): int
    {
        // The workbook is named in one place only — TaxUnitCatalog — so the
        // command and the report screens can never drift onto different
        // revisions of the same tax book.
        $file = $this->option('file') ?: storage_path(TaxUnitCatalog::WORKBOOK);
        $sheet = $this->option('sheet') ?: TaxUnitCatalog::SHEET;
        $startDate = $this->option('start_date');
        $endDate = $this->option('end_date');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $this->info("=== Đối soát TK 152: {$startDate} .. {$endDate} ===");
        $source = basename($file).' — sheet "'.$sheet.'"';
        $this->line('  Sổ thuế    : '.$source);
        $this->line('  Loại xuất  : '.($this->option('gi_types') ?: 'tất cả trừ 3 (điều chuyển)'));
        $this->line('  Mặt hàng   : '.($this->option('with_processed') ? 'cả bán thành phẩm' : 'chỉ NVL thô'));
        $this->line('  Kho        : '.implode(', ', $this->warehouses()));

        if ($this->option('gi_minus')) {
            $out = $this->readStockOutTypes($startDate, $endDate, self::parseTypes($this->option('gi_minus')));
            $this->line('  TRỪ xuất   : loại '.$this->option('gi_minus').' — '
                .number_format(array_sum(array_column($out, 'cost'))).' đ trên '.count($out).' mặt hàng.');
        }

        if ($this->option('gr_plus')) {
            $in = $this->readStockIn($startDate, $endDate, 'gr_plus');
            $this->line('  CỘNG nhập  : loại '.$this->option('gr_plus').' — '
                .number_format(array_sum(array_column($in, 'cost'))).' đ trên '.count($in).' mặt hàng.');
        }

        $tax = $this->readTaxSheet($file, $sheet);

        if ($tax === null) {
            return self::FAILURE;
        }

        $this->info('  Sổ thuế: '.count($tax).' mặt hàng.');

        $ivt = $this->readIvt($startDate, $endDate);
        $this->info('  IVT (4 cửa hàng): '.count($ivt).' mặt hàng.');

        $allWarehouses = $this->readIvtAllWarehouses($startDate, $endDate);
        $this->info('  IVT (cả 6 kho, tham chiếu): '.count($allWarehouses).' mặt hàng.');

        $transfers = $this->readTransfers($startDate, $endDate);
        $this->line('  Đã loại XNB điều chuyển nội bộ: '
            .number_format(array_sum(array_column($transfers, 'cost'))).' đ trên '
            .count($transfers).' mặt hàng (tránh đếm 2 lần).');

        $excluded = $this->excludedProcessed($startDate, $endDate);
        $this->line('  Đã loại bán thành phẩm (có công thức): '
            .number_format(array_sum(array_column($excluded, 'cost'))).' đ trên '
            .count($excluded).' mặt hàng — NVL của chúng đã được tính ở XSD.');

        $report = $this->compare($tax, $ivt, $allWarehouses, $transfers);
        $report['excluded'] = $excluded;
        $report['pricing'] = $this->buildPricing($report['matched']);
        $report['priceList'] = $this->buildPriceList($report['matched']);

        $this->line('  Đối soát được : '.count($report['matched']));
        $this->line('  Chờ quy đổi   : '.count($report['pending']));
        $this->line('  Chỉ có ở thuế : '.count($report['taxOnly']));
        $this->line('  Chỉ có ở IVT  : '.count($report['ivtOnly']));

        $suffix = $this->option('suffix') ? '_'.$this->option('suffix') : '';
        // Named after the account, not the sheet: the accountant renames the
        // sheet with every revision, and output files that rename themselves
        // stop being comparable run to run.
        $out = storage_path('app/doi-soat/doi_soat_152_'.$startDate.'_'.$endDate.$suffix.'.xlsx');
        $this->write($out, $report, $startDate, $endDate, $source);

        $this->newLine();
        $this->info("=== Đã xuất: {$out} ===");

        // Loud, because a stale catalog is a reason to distrust the run rather
        // than an incidental detail: a packaging size confirmed since the cache
        // was written would change the quantities above.
        foreach (array_filter([$this->units->staleWarning(), $this->recipes->staleWarning()]) as $warning) {
            $this->warn('  ⚠ '.$warning);
        }

        return self::SUCCESS;
    }

    /**
     * Read the TK 152 sheet. Layout (row 4/5 are two header rows):
     *   A Tên kho | B Mã hàng | C Tên hàng | D ĐVT
     *   E,F Đầu kỳ | G..L Nhập kho | M,N SL/GT bán hàng | O,P Xuất kho | Q,R Cuối kỳ
     * The reconciliation uses O/P — total goods issued in the period.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function readTaxSheet(string $file, string $sheet): ?array
    {
        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly($sheet);

        $worksheet = $reader->load($file)->getSheetByName($sheet);

        if (! $worksheet) {
            $this->error("Sheet '{$sheet}' not found in {$file}");

            return null;
        }

        $items = [];

        foreach ($worksheet->toArray(null, true, false, false) as $i => $row) {
            if ($i < 5) {
                continue;
            }

            $code = trim((string) ($row[1] ?? ''));
            $warehouse = trim((string) ($row[0] ?? ''));

            if ($code === '' || $warehouse === '' || $warehouse === 'Tổng cộng') {
                continue;
            }

            $items[$code] = [
                'name' => trim((string) ($row[2] ?? '')),
                'unit' => trim((string) ($row[3] ?? '')),
                'qty' => (float) ($row[14] ?? 0),
                'val' => (float) ($row[15] ?? 0),
                // Nhập kho: K/L is everything received, G/H the purchases only.
                // Kept because the issue valuation is skewed by the opening
                // balance, so the inbound price is worth comparing against.
                'in_qty' => (float) ($row[10] ?? 0),
                'in_val' => (float) ($row[11] ?? 0),
                'buy_qty' => (float) ($row[6] ?? 0),
                'buy_val' => (float) ($row[7] ?? 0),
                'open_qty' => (float) ($row[4] ?? 0),
                'open_val' => (float) ($row[5] ?? 0),
            ];
        }

        return $items;
    }

    /**
     * Total stock-out per item across every warehouse, kept only as a
     * reference column: when an item barely moves in the four shops but does
     * move company-wide, the gap is a kitchen-vs-shop split rather than a real
     * discrepancy (SUATUOITH is the textbook case).
     *
     * @return array<string, array{qty: float, cost: float}>
     */
    private function readIvtAllWarehouses(string $startDate, string $endDate): array
    {
        return $this->forGiTypes($this->rawMaterialsOnly(StockOutSummary::query()))
            ->where('period_from', '>=', $startDate)
            ->where('period_to', '<=', $endDate)
            ->selectRaw('item_id, SUM(main_unit_qty) AS qty, SUM(amount_cost) AS cost')
            ->groupBy('item_id')
            ->get()
            ->mapWithKeys(fn ($r): array => [$r->item_id => [
                'qty' => (float) $r->qty,
                'cost' => (float) $r->cost,
            ]])
            ->all();
    }

    /**
     * Goods the internal warehouses shipped to the shops (XNB). Excluded from
     * every consumption figure — shown only so the size of what was removed is
     * visible.
     *
     * @return array<string, array{qty: float, cost: float}>
     */
    private function readTransfers(string $startDate, string $endDate): array
    {
        return $this->rawMaterialsOnly(StockOutSummary::query())
            ->where('gi_type', self::GI_TYPE_TRANSFER)
            ->whereIn('from_warehouse_id', self::INTERNAL_WAREHOUSES)
            ->where('period_from', '>=', $startDate)
            ->where('period_to', '<=', $endDate)
            ->selectRaw('item_id, SUM(main_unit_qty) AS qty, SUM(amount_cost) AS cost')
            ->groupBy('item_id')
            ->get()
            ->mapWithKeys(fn ($r): array => [$r->item_id => [
                'qty' => (float) $r->qty,
                'cost' => (float) $r->cost,
            ]])
            ->all();
    }

    /**
     * Total stock-out per item across the four shop warehouses.
     *
     * @return array<string, array{name: string, unit: string, qty: float, cost: float, amount: float}>
     */
    private function readIvt(string $startDate, string $endDate): array
    {
        $issues = $this->forGiTypes($this->rawMaterialsOnly(StockOutSummary::query()))
            ->whereIn('from_warehouse_id', $this->warehouses())
            ->where('period_from', '>=', $startDate)
            ->where('period_to', '<=', $endDate)
            ->selectRaw('item_id,
                         MAX(item_name) AS item_name,
                         MAX(main_unit_id) AS unit,
                         SUM(main_unit_qty) AS qty,
                         SUM(amount_cost) AS cost,
                         SUM(amount) AS amount')
            ->groupBy('item_id')
            ->get()
            ->mapWithKeys(fn ($r): array => [$r->item_id => [
                'name' => (string) $r->item_name,
                'unit' => (string) $r->unit,
                'qty' => (float) $r->qty,
                'cost' => (float) $r->cost,
                'amount' => (float) $r->amount,
            ]])
            ->all();

        // The formula is spelled out by the options rather than hard-coded,
        // because which categories add and which subtract is an accounting
        // judgement, not something the data dictates.
        $adjust = function (array $source, float $sign) use (&$issues): void {
            foreach ($source as $code => $entry) {
                if (! isset($issues[$code])) {
                    continue;
                }

                $issues[$code]['qty'] += $sign * $entry['qty'];
                $issues[$code]['cost'] += $sign * $entry['cost'];
                $issues[$code]['amount'] += $sign * $entry['cost'];
            }
        };

        $adjust($this->readStockOutTypes($startDate, $endDate, self::parseTypes($this->option('gi_minus'))), -1.0);
        $adjust($this->readStockIn($startDate, $endDate, 'gr_plus'), 1.0);

        return $issues;
    }

    /**
     * How many IVT units make up one tax unit, or null when it cannot be known
     * without the customer confirming the packaging size for that item.
     */
    private function conversionFactor(string $itemId, string $taxUnit, string $ivtUnit): ?float
    {
        // A hand-confirmed factor always wins over anything inferred.
        $manual = self::manualFactor($itemId, $ivtUnit, $this->units);

        if ($manual !== null) {
            return $manual;
        }

        return $this->units->factor($itemId, $taxUnit, $ivtUnit);
    }

    /**
     * The note shown for an item: any ruling the customer has given, plus a
     * marker when the item belongs to a mixed-up group whose per-item variance
     * should not be read on its own.
     */
    private function noteFor(string $itemId): string
    {
        $parts = [];

        foreach (self::ITEM_GROUPS as $group => $members) {
            if (in_array($itemId, $members, true)) {
                $parts[] = "Thuộc nhóm \"{$group}\" — sổ thuế ghi lẫn giữa các mã trong nhóm, chỉ xét tổng nhóm.";
            }
        }

        if (isset(self::ITEM_NOTES[$itemId])) {
            $parts[] = self::ITEM_NOTES[$itemId];
        }

        return implode(' ', $parts);
    }

    /**
     * Value-only reconciliation for each mixed-up group: one row per member
     * plus a total row. Quantities are omitted on purpose — members are counted
     * in different units, so only money can be added up.
     *
     * @param  array<string, array<string, mixed>>  $tax
     * @param  array<string, array<string, mixed>>  $ivt
     * @param  array<string, array{qty: float, cost: float}>  $allWarehouses
     * @return array<int, array<int, mixed>>
     */
    private function buildGroups(array $tax, array $ivt, array $allWarehouses): array
    {
        $rows = [];

        foreach (self::ITEM_GROUPS as $group => $members) {
            $taxTotal = $ivt4Total = $ivt6Total = 0.0;

            foreach ($members as $code) {
                $taxVal = (float) ($tax[$code]['val'] ?? 0);
                $ivt4Val = (float) ($ivt[$code]['cost'] ?? 0);
                $ivt6Val = (float) ($allWarehouses[$code]['cost'] ?? 0);

                $taxTotal += $taxVal;
                $ivt4Total += $ivt4Val;
                $ivt6Total += $ivt6Val;

                $rows[] = [
                    $group, $code,
                    $tax[$code]['name'] ?? '(không có ở sổ thuế)',
                    $ivt[$code]['name'] ?? ($allWarehouses[$code] ?? null ? '(chỉ có ở kho khác)' : '(không có ở IVT)'),
                    $taxVal, $ivt4Val, $ivt6Val, '',
                ];
            }

            $rows[] = [
                $group, 'TỔNG NHÓM', '', '',
                $taxTotal, $ivt4Total, $ivt6Total,
                'Chênh so với cả 6 kho: '.number_format($ivt6Total - $taxTotal).
                ($taxTotal > 0 ? sprintf(' (%+.1f%%)', ($ivt6Total - $taxTotal) / $taxTotal * 100) : ''),
            ];
        }

        return $rows;
    }

    /**
     * Units the IVT catalog knows for an item — shown in the "Chờ quy đổi"
     * sheet so it is obvious what the accountant's label has to be matched to.
     */
    private function knownUnits(string $itemId): string
    {
        $units = $this->units->unitsFor($itemId);

        return $units === [] ? '(IVT không có quy đổi nào)' : implode(', ', $units);
    }

    /**
     * @param  array<string, array<string, mixed>>  $tax
     * @param  array<string, array<string, mixed>>  $ivt
     * @param  array<string, array{qty: float, cost: float}>  $allWarehouses
     * @param  array<string, array{qty: float, cost: float}>  $transfers
     * @return array{matched: array<int, array<int, mixed>>, pending: array<int, array<int, mixed>>, taxOnly: array<int, array<int, mixed>>, ivtOnly: array<int, array<int, mixed>>}
     */
    private function compare(array $tax, array $ivt, array $allWarehouses, array $transfers): array
    {
        $matched = $pending = $taxOnly = $ivtOnly = [];

        foreach ($tax as $code => $t) {
            $all = $allWarehouses[$code] ?? ['qty' => 0.0, 'cost' => 0.0];
            $moved = $transfers[$code] ?? ['qty' => 0.0, 'cost' => 0.0];
            $note = $this->noteFor($code);

            if (! isset($ivt[$code])) {
                $taxOnly[] = [
                    $code, $t['name'], $t['unit'], $t['qty'], $t['val'],
                    $t['qty'] > 0 ? $t['val'] / $t['qty'] : 0,
                    $all['qty'], $all['cost'], $moved['cost'], $note,
                ];

                continue;
            }

            $i = $ivt[$code];
            $factor = $this->conversionFactor($code, $t['unit'], $i['unit']);

            if ($factor === null) {
                $pending[] = [
                    $code, $t['name'], $i['name'],
                    $t['unit'], $i['unit'],
                    $t['qty'], $i['qty'],
                    $t['val'], $i['cost'],
                    '1 '.$t['unit'].' = ? '.$i['unit'],
                    $this->knownUnits($code),
                    $all['qty'], $all['cost'], $moved['cost'], $note,
                ];

                continue;
            }

            // Bring the IVT quantity into the tax book's unit.
            $ivtQty = $factor != 0.0 ? $i['qty'] / $factor : 0.0;

            $qtyDiff = $ivtQty - $t['qty'];
            $qtyPct = $t['qty'] != 0.0 ? $qtyDiff / $t['qty'] * 100 : null;

            $valDiff = $i['cost'] - $t['val'];
            $valPct = $t['val'] != 0.0 ? $valDiff / $t['val'] * 100 : null;

            $taxPrice = $t['qty'] != 0.0 ? $t['val'] / $t['qty'] : 0.0;
            $ivtPrice = $ivtQty != 0.0 ? $i['cost'] / $ivtQty : 0.0;
            $priceDiff = $ivtPrice - $taxPrice;
            $pricePct = $taxPrice != 0.0 ? $priceDiff / $taxPrice * 100 : null;

            $inPrice = ($t['in_qty'] ?? 0) > 0 ? $t['in_val'] / $t['in_qty'] : 0.0;
            $buyPrice = ($t['buy_qty'] ?? 0) > 0 ? $t['buy_val'] / $t['buy_qty'] : 0.0;
            $openPrice = ($t['open_qty'] ?? 0) > 0 ? $t['open_val'] / $t['open_qty'] : 0.0;

            $matched[] = [
                $code, $t['name'], $i['name'],
                $t['unit'], $i['unit'], $factor,
                $t['qty'], $ivtQty, $qtyDiff, $qtyPct,
                $taxPrice, $ivtPrice, $priceDiff, $pricePct,
                $t['val'], $i['cost'], $valDiff, $valPct,
                $i['amount'],
                $factor != 0.0 ? $all['qty'] / $factor : 0.0, $all['cost'],
                $moved['cost'],
                $note,
                $inPrice, $buyPrice, $openPrice,
            ];
        }

        foreach ($ivt as $code => $i) {
            if (! isset($tax[$code])) {
                $all = $allWarehouses[$code] ?? ['qty' => 0.0, 'cost' => 0.0];

                $ivtOnly[] = [
                    $code, $i['name'], $i['unit'], $i['qty'], $i['cost'],
                    $i['qty'] > 0 ? $i['cost'] / $i['qty'] : 0,
                    $all['qty'], $all['cost'],
                    (float) ($transfers[$code]['cost'] ?? 0),
                    $this->noteFor($code),
                ];
            }
        }

        // Largest absolute value gap first — that is what needs explaining.
        usort($matched, fn ($a, $b) => abs($b[16]) <=> abs($a[16]));
        usort($taxOnly, fn ($a, $b) => $b[4] <=> $a[4]);
        usort($ivtOnly, fn ($a, $b) => $b[4] <=> $a[4]);

        $groups = $this->buildGroups($tax, $ivt, $allWarehouses);

        return compact('matched', 'pending', 'taxOnly', 'ivtOnly', 'groups');
    }

    /**
     * @param  array{matched: array, pending: array, taxOnly: array, ivtOnly: array}  $report
     */
    private function write(string $path, array $report, string $startDate, string $endDate, string $source): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        $this->addSummarySheet($book, $report, $startDate, $endDate, $source);

        $this->addSheet($book, 'Đối soát', [
            'Mã hàng', 'Tên hàng (thuế)', 'Tên hàng (IVT)',
            'ĐVT thuế', 'ĐVT IVT', 'Hệ số (1 ĐVT thuế = ? ĐVT IVT)',
            'SL thuế', 'SL IVT (quy về ĐVT thuế)', 'Chênh SL', 'Chênh SL %',
            'Đơn giá thuế', 'Đơn giá IVT', 'Chênh đơn giá', 'Chênh đơn giá %',
            'Giá trị thuế', 'Giá trị IVT (giá vốn)', 'Chênh giá trị', 'Chênh giá trị %',
            'Giá trị IVT (amount, tham chiếu)',
            'SL IVT cả 6 kho (quy về ĐVT thuế)', 'Giá trị IVT cả 6 kho',
            'Điều chuyển nội bộ đã loại (XNB)',
            'Ghi chú',
        ], $report['matched'], [
            6 => '#,##0.000', 7 => '#,##0.000', 8 => '#,##0.000', 9 => '0.00"%"',
            10 => '#,##0.00', 11 => '#,##0.00', 12 => '#,##0.00', 13 => '0.00"%"',
            14 => '#,##0', 15 => '#,##0', 16 => '#,##0', 17 => '0.00"%"', 18 => '#,##0',
            19 => '#,##0.000', 20 => '#,##0', 21 => '#,##0',
        ]);

        $this->addPriceListSheet($book, $report['priceList']);

        $this->addSheet($book, 'Hai bảng giá', [
            'Mã hàng', 'Tên hàng (thuế)', 'ĐVT (theo sổ thuế)',
            'SL thuế', 'SL IVT', 'Chênh SL', 'Chênh SL %',
            'Đơn giá THUẾ', 'Thành tiền SL thuế × giá THUẾ', 'Thành tiền SL IVT × giá THUẾ',
            'SAI LỆCH theo bảng giá THUẾ', 'Sai lệch %',
            'Đơn giá IVT', 'Thành tiền SL thuế × giá IVT', 'Thành tiền SL IVT × giá IVT',
            'SAI LỆCH theo bảng giá IVT', 'Sai lệch %',
            'Đơn giá NHẬP (sổ thuế)', 'Thành tiền SL thuế × giá NHẬP', 'Thành tiền SL IVT × giá NHẬP',
            'SAI LỆCH theo bảng giá NHẬP', 'Sai lệch %',
            'Giá mua vào (sổ thuế)', 'Đơn giá tồn ĐẦU KỲ (sổ thuế)',
            'Giá chuẩn (nếu đã xác nhận)', 'Ghi chú',
        ], $report['pricing'], [
            3 => '#,##0.000', 4 => '#,##0.000', 5 => '#,##0.000', 6 => '0.00"%"',
            7 => '#,##0.00', 8 => '#,##0', 9 => '#,##0', 10 => '#,##0', 11 => '0.00"%"',
            12 => '#,##0.00', 13 => '#,##0', 14 => '#,##0', 15 => '#,##0', 16 => '0.00"%"',
            17 => '#,##0.00', 18 => '#,##0', 19 => '#,##0', 20 => '#,##0', 21 => '0.00"%"',
            22 => '#,##0.00', 23 => '#,##0.00', 24 => '#,##0.00',
        ]);

        $this->addSheet($book, 'Đối soát theo nhóm', [
            'Nhóm', 'Mã hàng', 'Tên hàng (thuế)', 'Tên hàng (IVT)',
            'Giá trị thuế', 'Giá trị IVT (4 cửa hàng)', 'Giá trị IVT (cả 6 kho)',
            'Ghi chú',
        ], $report['groups'], [
            4 => '#,##0', 5 => '#,##0', 6 => '#,##0',
        ]);

        $this->addSheet($book, 'Chờ quy đổi', [
            'Mã hàng', 'Tên hàng (thuế)', 'Tên hàng (IVT)',
            'ĐVT thuế', 'ĐVT IVT',
            'SL thuế', 'SL IVT (chưa quy đổi)',
            'Giá trị thuế', 'Giá trị IVT (giá vốn)',
            'Cần xác nhận', 'Đơn vị IVT có quy đổi sẵn',
            'SL IVT cả 6 kho', 'Giá trị IVT cả 6 kho',
            'Điều chuyển nội bộ đã loại (XNB)', 'Ghi chú',
        ], $report['pending'], [
            5 => '#,##0.000', 6 => '#,##0.000', 7 => '#,##0', 8 => '#,##0',
            11 => '#,##0.000', 12 => '#,##0', 13 => '#,##0',
        ]);

        $this->addSheet($book, 'Chỉ có ở thuế', [
            'Mã hàng', 'Tên hàng', 'ĐVT', 'SL xuất', 'Giá trị xuất', 'Đơn giá',
            'SL IVT cả 6 kho (ĐVT của IVT)', 'Giá trị IVT cả 6 kho',
            'Điều chuyển nội bộ đã loại (XNB)', 'Ghi chú',
        ], $report['taxOnly'], [
            3 => '#,##0.000', 4 => '#,##0', 5 => '#,##0.00',
            6 => '#,##0.000', 7 => '#,##0', 8 => '#,##0',
        ]);

        $this->addSheet($book, 'Bán thành phẩm đã loại', [
            'Mã hàng', 'Tên hàng', 'ĐVT', 'SL xuất (cả 6 kho)', 'Giá trị xuất (giá vốn)',
        ], $report['excluded'], [
            3 => '#,##0.000', 4 => '#,##0',
        ]);

        $this->addSheet($book, 'Chỉ có ở IVT', [
            'Mã hàng', 'Tên hàng', 'ĐVT', 'SL xuất', 'Giá trị xuất (giá vốn)', 'Đơn giá',
            'SL IVT cả 6 kho', 'Giá trị IVT cả 6 kho',
            'Điều chuyển nội bộ đã loại (XNB)', 'Ghi chú',
        ], $report['ivtOnly'], [
            3 => '#,##0.000', 4 => '#,##0', 5 => '#,##0.00',
            6 => '#,##0.000', 7 => '#,##0', 8 => '#,##0',
        ]);

        (new XlsxWriter($book))->save($path);
        $book->disconnectWorksheets();
    }

    /**
     * @param  array{matched: array, pending: array, taxOnly: array, ivtOnly: array}  $report
     */
    private function addSummarySheet(Spreadsheet $book, array $report, string $startDate, string $endDate, string $source): void
    {
        $ws = $book->createSheet();
        $ws->setTitle('Tổng hợp');

        $taxQtyItems = count($report['matched']) + count($report['pending']) + count($report['taxOnly']);
        $taxVal = array_sum(array_column($report['matched'], 14))
            + array_sum(array_column($report['pending'], 7))
            + array_sum(array_column($report['taxOnly'], 4));
        $ivtVal = array_sum(array_column($report['matched'], 15))
            + array_sum(array_column($report['pending'], 8))
            + array_sum(array_column($report['ivtOnly'], 4));

        $rows = [
            ['ĐỐI SOÁT TK 152 — SỔ THUẾ vs IVT (4 CỬA HÀNG)'],
            ['Kỳ', $startDate.' .. '.$endDate],
            ['Nguồn sổ thuế', $source],
            ['Kho IVT được cộng', implode(', ', $this->warehouses())],
            ['Loại xuất được tính', $this->option('gi_types') ?: 'tất cả trừ 3 (điều chuyển)'],
            ['Phạm vi mặt hàng', $this->option('with_processed') ? 'cả bán thành phẩm' : 'chỉ NVL thô'],
            ['Công thức IVT', 'Xuất('.($this->option('gi_types') ?: 'tất cả trừ 3').')'
                .($this->option('gi_minus') ? ' − Xuất('.$this->option('gi_minus').')' : '')
                .($this->option('gr_plus') ? ' + Nhập('.$this->option('gr_plus').')' : '')],
            [],
            ['Nhóm', 'Số mặt hàng', 'Giá trị thuế', 'Giá trị IVT (giá vốn)', 'Chênh lệch'],
            [
                'Đối soát được (đơn vị khớp)', count($report['matched']),
                array_sum(array_column($report['matched'], 14)),
                array_sum(array_column($report['matched'], 15)),
                array_sum(array_column($report['matched'], 16)),
            ],
            [
                'Chờ xác nhận quy đổi đơn vị', count($report['pending']),
                array_sum(array_column($report['pending'], 7)),
                array_sum(array_column($report['pending'], 8)),
                array_sum(array_column($report['pending'], 8)) - array_sum(array_column($report['pending'], 7)),
            ],
            [
                'Chỉ có ở sổ thuế', count($report['taxOnly']),
                array_sum(array_column($report['taxOnly'], 4)), 0,
                -array_sum(array_column($report['taxOnly'], 4)),
            ],
            [
                'Chỉ có ở IVT', count($report['ivtOnly']), 0,
                array_sum(array_column($report['ivtOnly'], 4)),
                array_sum(array_column($report['ivtOnly'], 4)),
            ],
            ['TỔNG', $taxQtyItems, $taxVal, $ivtVal, $ivtVal - $taxVal],
        ];

        // Two price lists, one quantity gap: the same physical difference in
        // consumption costed with each side's prices. Quantities themselves are
        // not added up — the items use different units.
        $pricing = $report['pricing'];
        $clean = $pricing === [] ? null : end($pricing);
        $totals = $pricing === [] ? null : prev($pricing);

        if ($totals) {
            $taxAtTax = (float) $totals[8];
            $ivtAtTax = (float) $totals[9];
            $taxAtIvt = (float) $totals[13];
            $ivtAtIvt = (float) $totals[14];

            $rows[] = [];
            $rows[] = ['HAI BẢNG GIÁ — '.(count($pricing) - 2).' mặt hàng đối soát được'];
            $rows[] = ['Chênh lệch nằm ở SỐ LƯỢNG; hai bảng giá chỉ định giá khoản chênh đó theo hai cách.'];
            $rows[] = [];
            $rows[] = ['Bảng giá áp dụng', 'Thành tiền theo SL THUẾ', 'Thành tiền theo SL IVT', 'SAI LỆCH', '%'];
            $rows[] = [
                'Theo bảng giá THUẾ', $taxAtTax, $ivtAtTax, $ivtAtTax - $taxAtTax,
                $taxAtTax != 0.0 ? ($ivtAtTax - $taxAtTax) / $taxAtTax * 100 : 0,
            ];
            $rows[] = [
                'Theo bảng giá IVT', $taxAtIvt, $ivtAtIvt, $ivtAtIvt - $taxAtIvt,
                $taxAtIvt != 0.0 ? ($ivtAtIvt - $taxAtIvt) / $taxAtIvt * 100 : 0,
            ];

            $taxAtIn = (float) $totals[18];
            $ivtAtIn = (float) $totals[19];
            $rows[] = [
                'Theo bảng giá NHẬP (sổ thuế)', $taxAtIn, $ivtAtIn, $ivtAtIn - $taxAtIn,
                $taxAtIn != 0.0 ? ($ivtAtIn - $taxAtIn) / $taxAtIn * 100 : 0,
            ];

            if ($clean) {
                $rows[] = [];
                $rows[] = ['Sau khi loại dòng sổ thuế ghi sai ('.implode(', ', self::TAX_DATA_ERRORS).')'];
                $rows[] = [
                    'Theo bảng giá THUẾ', (float) $clean[8], (float) $clean[9], (float) $clean[10],
                    (float) $clean[8] != 0.0 ? (float) $clean[10] / (float) $clean[8] * 100 : 0,
                ];
                $rows[] = [
                    'Theo bảng giá IVT', (float) $clean[13], (float) $clean[14], (float) $clean[15],
                    (float) $clean[13] != 0.0 ? (float) $clean[15] / (float) $clean[13] * 100 : 0,
                ];
                $rows[] = [
                    'Theo bảng giá NHẬP (sổ thuế)', (float) $clean[18], (float) $clean[19], (float) $clean[20],
                    (float) $clean[18] != 0.0 ? (float) $clean[20] / (float) $clean[18] * 100 : 0,
                ];
            }
        }

        $ws->fromArray($rows, null, 'A1');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $ws->getStyle('A5:E5')->getFont()->setBold(true);
        $ws->getStyle('A10:E10')->getFont()->setBold(true);
        $ws->getStyle('C6:E10')->getNumberFormat()->setFormatCode('#,##0');
        $ws->getStyle('A12')->getFont()->setBold(true)->setSize(12);
        $ws->getStyle('A16:E16')->getFont()->setBold(true);
        $ws->getStyle('A20')->getFont()->setBold(true);
        $ws->getStyle('B17:D26')->getNumberFormat()->setFormatCode('#,##0');
        $ws->getStyle('E17:E26')->getNumberFormat()->setFormatCode('0.00"%"');

        foreach (range('A', 'E') as $col) {
            $ws->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * The price list, shaded by how far the two books disagree so the rows
     * worth ruling on stand out.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function addPriceListSheet(Spreadsheet $book, array $rows): void
    {
        $this->addSheet($book, 'Bảng giá', [
            'Mã hàng', 'Tên hàng', 'ĐVT (theo sổ thuế)',
            'Đơn giá THUẾ (xuất kho)', 'Đơn giá IVT', 'IVT lệch thuế %',
            'Đơn giá NHẬP (sổ thuế)', 'Đơn giá tồn ĐẦU KỲ (sổ thuế)',
            'GIÁ CHUẨN (điền vào đây)', 'Kết luận', 'Mức lệch',
            'SL IVT', 'Giá trị IVT', 'Ghi chú',
        ], $rows, [
            3 => '#,##0.00', 4 => '#,##0.00', 5 => '0.0"%"',
            6 => '#,##0.00', 7 => '#,##0.00', 8 => '#,##0.00',
            11 => '#,##0.000', 12 => '#,##0',
        ]);

        $ws = $book->getSheetByName('Bảng giá');

        // Red past 50%, orange past 30%, yellow past 10% — a glance is enough
        // to see which rows still need a ruling.
        $shades = [50 => 'F8CBAD', 30 => 'FCE4D6', 10 => 'FFF2CC'];

        foreach ($rows as $index => $row) {
            $gap = abs((float) ($row[5] ?? 0));

            foreach ($shades as $threshold => $rgb) {
                if ($gap >= $threshold) {
                    $line = $index + 2;
                    $ws->getStyle("A{$line}:N{$line}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
                    break;
                }
            }
        }

        // The column meant to be typed into gets a border so it reads as input.
        $last = max(2, count($rows) + 1);
        $ws->getStyle("I2:I{$last}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
    }

    /**
     * @param  string[]  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $formats  zero-based column index => number format
     */
    private function addSheet(Spreadsheet $book, string $title, array $headers, array $rows, array $formats = []): void
    {
        $ws = $book->createSheet();
        $ws->setTitle($title);
        $ws->fromArray($headers, null, 'A1');

        if ($rows !== []) {
            $ws->fromArray($rows, null, 'A2');
        }

        $lastCol = $ws->getHighestColumn();
        $lastRow = max(2, count($rows) + 1);

        $ws->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $ws->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
        $ws->getStyle("A1:{$lastCol}1")->getAlignment()
            ->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getRowDimension(1)->setRowHeight(32);
        $ws->freezePane('A2');
        $ws->setAutoFilter("A1:{$lastCol}{$lastRow}");

        foreach ($formats as $index => $format) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $ws->getStyle("{$col}2:{$col}{$lastRow}")->getNumberFormat()->setFormatCode($format);
        }

        foreach (range(1, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastCol)) as $index) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
            $ws->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
