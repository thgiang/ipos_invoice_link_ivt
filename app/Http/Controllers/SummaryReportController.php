<?php

namespace App\Http\Controllers;

use App\Services\RecipeExploder;
use App\Services\TaxUnitCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only screens over stock_out_summaries / stock_in_summaries.
 *
 * Both tables hold IVT's *aggregated* reports: a row is one item × warehouse ×
 * type summed over a whole period, and the crawler chops every range into
 * calendar months. A row therefore has no date of its own, so a day-level
 * filter can only be honoured at month granularity — the screens include the
 * months lying wholly inside the chosen range and say out loud which months
 * were left out, rather than pro-rating a partial month into a number nobody
 * can trace back.
 */
class SummaryReportController extends Controller
{
    /** @var array<int, string> */
    private const GI_TYPES = [
        1 => 'XBH — Xuất bán hàng',
        2 => 'XDC — Xuất điều chỉnh kho (kiểm kê)',
        3 => 'XNB — Xuất điều chuyển nội bộ',
        4 => 'XH — Xuất hủy',
        5 => 'XK — Xuất nhân viên dùng',
        6 => 'XSD — Xuất sử dụng (Bếp chế biến)',
    ];

    /** @var array<int, string> */
    private const GR_TYPES = [
        1 => 'Nhập mua hàng',
        2 => 'Nhập điều chỉnh kho (kiểm kê)',
        3 => 'Nhập điều chuyển nội bộ',
        4 => 'Nhập thành phẩm sau chế biến',
        5 => 'Nhập khác',
    ];

    /**
     * XNB / nhập điều chuyển move the same goods twice through the summary, so
     * they are off by default; the checkbox stays available because seeing the
     * transfer volume on its own is sometimes the point.
     */
    private const TRANSFER_TYPE = 3;

    /**
     * XSD — the raw materials a shop burns to make a semi-finished good.
     *
     * Exploding recipes while this type is selected counts the same material
     * twice: once as the XSD issue, and again inside the finished topping when
     * that is sold on gi_type 1. Measured on 2026 data the overlap is about
     * 1,27 tỷ, so the screen warns instead of letting it pass.
     */
    private const PROCESSING_TYPE = 6;

    /**
     * The months the screens offer. The monthly TK 152 books are named by month
     * with no year, so a single year is all the naming scheme can express.
     */
    private const YEAR = 2026;

    private const FIRST_MONTH = 1;

    private const LAST_MONTH = 9;

    /**
     * Tháng cuối của kỳ 6 tháng. Cột Tỉ lệ lấy mốc này để biết còn bao nhiêu
     * tháng nữa cho chỗ tồn được tiêu hết.
     */
    private const LAST_PERIOD_MONTH = 6;

    /**
     * The basis of the TK 152 book: it is labelled "Bếp Trung Liệt" but is in
     * fact the total of these four shops (customer-confirmed, same list as
     * DoiSoat152). Filtering to anything else makes the "152" columns
     * incomparable, so the screen checks and says so.
     *
     * @var string[]
     */
    private const TAX_BOOK_WAREHOUSES = [
        'CHLANGHA',
        'CHGIANGVANMINH',
        'CHLETRONGTAN',
        'CHKHUCTHUADU',
    ];

    /**
     * Tháng đang xem — cột Tỉ lệ cần biết còn mấy tháng nữa để tiêu hết tồn.
     */
    private int $month = 1;

    public function __construct(
        private readonly TaxUnitCatalog $units,
        private readonly RecipeExploder $exploder,
    ) {}

    public function stockOut(Request $request): View
    {
        return $this->report($request, 'out');
    }

    public function stockIn(Request $request): View
    {
        return $this->report($request, 'in');
    }

    private function report(Request $request, string $side): View
    {
        $out = $side === 'out';

        $table = $out ? 'stock_out_summaries' : 'stock_in_summaries';
        $typeColumn = $out ? 'gi_type' : 'gr_type';
        $warehouseColumn = $out ? 'from_warehouse' : 'to_warehouse';
        $typeLabels = $out ? self::GI_TYPES : self::GR_TYPES;

        $periods = DB::table($table)
            ->select('period_from', 'period_to')
            ->distinct()
            ->orderBy('period_from')
            ->get();

        $warehouses = DB::table($table)
            ->select($warehouseColumn.'_id as code', $warehouseColumn.'_name as name')
            ->distinct()
            ->orderBy('name')
            ->get();

        // One calendar month, never a free range: a summary row has no date of
        // its own and is already aggregated per month, and the tax book now
        // arrives as one file per month too. Anything finer would be a number
        // nobody could trace back to a document.
        $months = $this->months($periods);
        $month = $this->month($request->input('month'), $months);
        $this->month = $month;
        $startDate = sprintf('%04d-%02d-01', self::YEAR, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        // Read live on every request — the accountant re-exports these files as
        // corrections come in, so nothing about them is cached.
        $bookExists = $this->units->useMonth($month);

        $selectedTypes = $this->types($request, $typeLabels);
        $selectedWarehouses = array_values(array_intersect(
            (array) $request->input('warehouses', []),
            $warehouses->pluck('code')->all()
        ));

        $included = $periods
            ->filter(fn ($p): bool => $p->period_from >= $startDate && $p->period_to <= $endDate)
            ->values();

        $explode = $request->boolean('explode');

        $rows = collect();
        $totals = [
            'qty' => 0.0, 'amount' => 0.0, 'cost' => 0.0, 'converted' => 0,
            'compared' => 0, 'book_val' => 0.0, 'cost_compared' => 0.0,
            'val_diff' => 0.0, 'val_pct' => null, 'book_raw' => 0.0,
            'unpriced' => 0, 'borrowed' => 0, 'odd' => 0, 'odd_value' => 0.0,
        ];
        $explosion = ['count' => 0, 'cost' => 0.0, 'items' => [], 'issues' => []];

        if ($selectedTypes !== [] && $included->isNotEmpty()) {
            $raw = DB::table($table)
                ->selectRaw('item_id,
                             MAX(item_name) AS item_name,
                             MAX(item_class_name) AS item_class_name,
                             main_unit_id AS unit_id,
                             MAX(main_unit_name) AS unit_name,
                             SUM(main_unit_qty) AS qty,
                             SUM(amount) AS amount,
                             SUM(amount_cost) AS amount_cost,
                             COUNT(*) AS row_count')
                ->whereIn($typeColumn, $selectedTypes)
                ->where('period_from', '>=', $startDate)
                ->where('period_to', '<=', $endDate)
                ->when($selectedWarehouses !== [], fn ($q) => $q->whereIn($warehouseColumn.'_id', $selectedWarehouses))
                ->groupBy('item_id', 'main_unit_id')
                ->get()
                ->map(fn ($r): array => [
                    'item_id' => (string) $r->item_id,
                    'item_name' => (string) $r->item_name,
                    'item_class_name' => (string) $r->item_class_name,
                    'unit_id' => (string) $r->unit_id,
                    'unit_name' => (string) $r->unit_name,
                    'qty' => (float) $r->qty,
                    'amount' => (float) $r->amount,
                    'amount_cost' => (float) $r->amount_cost,
                    'row_count' => (int) $r->row_count,
                    'from_recipe' => 0.0,
                ])
                ->all();

            if ($explode) {
                $raw = $this->explodeRecipes($raw, $explosion);
            }

            $rows = collect($raw)
                ->map(fn (array $r): array => $this->compare($r))
                // Biggest revalued IVT figure first. A row with no price at all
                // cannot be ranked that way, so it sinks below every priced row
                // while keeping a sensible order among its own kind — IVT's cost
                // is untrusted as money but still ranks items by rough size.
                ->sortByDesc(fn (array $r): float => $r['ivt_value'] ?? -1 / max($r['amount_cost'], 1.0))
                ->values();

            // The tax-book subtotal may only be set against the IVT figures of
            // the very same items: summing all of IVT against a book that
            // carries 118 codes would invent a gap out of the codes it lacks.
            $comparable = $rows->filter(fn (array $r): bool => $r['ivt_value'] !== null && $r['book_value'] !== null);

            $totals = [
                'qty' => $rows->sum('qty'),
                'amount' => $rows->sum('amount'),
                'cost' => $rows->sum('amount_cost'),
                // What the book itself recorded (column P), kept as the one
                // figure on the page that can be traced straight back to the
                // accountant's file.
                'book_raw' => $rows->whereNotNull('book_val')->sum('book_val'),
                // Codes present in the book but with no purchase price anywhere
                // in the year: they cannot be valued at all, so they are named
                // rather than quietly dropped from the totals.
                'unpriced' => $rows->filter(fn (array $r): bool => $r['book_val'] !== null && $r['book_price'] === null)->count(),
                'borrowed' => $rows->filter(fn (array $r): bool => $r['price_month'] !== null && $r['price_month'] !== $month)->count(),
                'odd' => $rows->filter(fn (array $r): bool => $r['price_odd'])->count(),
                'odd_value' => $rows->filter(fn (array $r): bool => $r['price_odd'])->sum('ivt_value'),
                // Quantities are in mixed units, so a grand total of them is
                // only ever a tally, never a measurement — count how many rows
                // could be restated in the tax book's unit so it is clear how
                // much of the table the "ĐVT 152" column actually covers.
                'converted' => $rows->whereNotNull('tax_qty')->count(),
                'compared' => $comparable->count(),
                'book_val' => $comparable->sum('book_value'),
                'cost_compared' => $comparable->sum('ivt_value'),
            ];

            $totals['val_diff'] = $totals['cost_compared'] - $totals['book_val'];
            $totals['val_pct'] = $totals['book_val'] != 0.0
                ? $totals['val_diff'] / abs($totals['book_val']) * 100
                : null;
        }

        return view('reports.summary', [
            'side' => $side,
            'title' => $out ? 'Tổng hợp XUẤT kho' : 'Tổng hợp NHẬP kho',
            'typeLabel' => $out ? 'Loại xuất (gi_type)' : 'Loại nhập (gr_type)',
            'typeLabels' => $typeLabels,
            'transferType' => self::TRANSFER_TYPE,
            'warehouseLabel' => $out ? 'Kho xuất' : 'Kho nhập',
            'warehouses' => $warehouses,
            'selectedTypes' => $selectedTypes,
            'selectedWarehouses' => $selectedWarehouses,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'month' => $month,
            'months' => $months,
            'year' => self::YEAR,
            'bookExists' => $bookExists,
            'bookUpdatedAt' => $this->units->sourceUpdatedAt(),
            'includedPeriods' => $included,
            'rows' => $rows,
            'totals' => $totals,
            'taxWorkbook' => $this->units->workbookName(),
            'taxError' => $this->units->loadError(),
            // Spelled out on the page so a figure can be traced back to the
            // column it came from without opening the code.
            'table' => $table,
            'typeColumn' => $typeColumn,
            'warehouseColumn' => $warehouseColumn.'_id',
            'taxPeriod' => $this->units->period(),
            'basisMismatch' => $this->basisMismatch($side, $startDate, $endDate, $selectedTypes, $selectedWarehouses),
            'taxBookWarehouses' => self::TAX_BOOK_WAREHOUSES,
            'explode' => $explode,
            'explosion' => $explosion,
            'doubleCount' => $explode && $side === 'out'
                && in_array(self::PROCESSING_TYPE, $selectedTypes, true),
        ]);
    }

    /**
     * Replace every processed item with the raw materials it was made from, and
     * fold those into the rows already present.
     *
     * Without this the two sides talk past each other: IVT books "Cốt TS Khoai
     * môn" while the tax book only ever knew about the trà nhài, sữa đặc and
     * bột sữa that went into it.
     *
     * @param  array<int, array<string, mixed>>  $raw
     * @param  array<string, mixed>  $stats
     * @return array<int, array<string, mixed>>
     */
    private function explodeRecipes(array $raw, array &$stats): array
    {
        // Ingredients land in whatever unit the recipe is written in; telling
        // the exploder how each item is already counted lets them merge with
        // the row on screen instead of opening a second one.
        $targetUnits = [];

        foreach ($raw as $row) {
            if (! $this->exploder->isProcessed($row['item_id'])) {
                $targetUnits[$row['item_id']] = $row['unit_id'];
            }
        }

        $merged = [];
        $exploded = [];
        $movedCost = 0.0;

        foreach ($raw as $row) {
            if (! $this->exploder->isProcessed($row['item_id'])) {
                $this->accumulate($merged, $row, 0.0);

                continue;
            }

            $parts = $this->exploder->explode(
                $row['item_id'], $row['item_name'], $row['unit_id'], $row['unit_name'],
                $row['qty'], $row['amount_cost'], $targetUnits,
            );

            // A recipe that could not be applied comes back as the item itself.
            if (count($parts) === 1 && $parts[0]['item_id'] === $row['item_id']) {
                $this->accumulate($merged, $row, 0.0);

                continue;
            }

            $exploded[] = $row['item_id'];
            $movedCost += $row['amount_cost'];

            foreach ($parts as $part) {
                $this->accumulate($merged, [
                    'item_id' => $part['item_id'],
                    'item_name' => $part['item_name'],
                    'item_class_name' => '',
                    'unit_id' => $part['unit'],
                    'unit_name' => $part['unit_name'],
                    'qty' => $part['qty'],
                    // The transaction-price column has no recipe to split it
                    // by, so the cost figure stands in for it rather than a
                    // number nobody could account for.
                    'amount' => $part['cost'],
                    'amount_cost' => $part['cost'],
                    'row_count' => 0,
                    'from_recipe' => $part['cost'],
                ], $part['cost']);
            }
        }

        $stats = [
            'count' => count($exploded),
            'cost' => $movedCost,
            'items' => $exploded,
            'issues' => $this->exploder->issues(),
        ];

        return array_values($merged);
    }

    /**
     * @param  array<string, array<string, mixed>>  $into
     * @param  array<string, mixed>  $row
     */
    private function accumulate(array &$into, array $row, float $fromRecipe): void
    {
        $key = $row['item_id'].'|'.$row['unit_id'];

        if (! isset($into[$key])) {
            $into[$key] = $row + ['from_recipe' => 0.0];
            $into[$key]['from_recipe'] = $fromRecipe;

            return;
        }

        $into[$key]['qty'] += $row['qty'];
        $into[$key]['amount'] += $row['amount'];
        $into[$key]['amount_cost'] += $row['amount_cost'];
        $into[$key]['row_count'] += $row['row_count'];
        $into[$key]['from_recipe'] += $fromRecipe;

        // A row created by an explosion carries no class; keep whichever name
        // and class actually came from the warehouse data.
        if ($into[$key]['item_class_name'] === '' && $row['item_class_name'] !== '') {
            $into[$key]['item_class_name'] = $row['item_class_name'];
        }
    }

    /**
     * Set one aggregated item against the tax book, in the tax book's unit.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function compare(array $r): array
    {
        $unitId = $r['unit_id'];
        $cost = $r['amount_cost'];

        // IVT quantity restated in the tax book's unit — the only basis on
        // which the two sides may be compared at all.
        $ivtQty = $this->units->convert($r['item_id'], $unitId, $r['qty']);
        $bookQty = $this->units->bookQty($r['item_id']);
        $bookEndQty = $this->units->bookEndQty($r['item_id']);
        $bookVal = $this->units->bookValue($r['item_id']);

        // Một bảng giá duy nhất cho cả hai bên: giá NHẬP KHO của sổ thuế.
        // Khách hàng chốt rằng giá bên IVT không đáng tin, chỉ số lượng mới tin
        // được — nên chính lệch tiền dưới đây thuần là chênh số lượng, giá đã
        // triệt tiêu vì hai bên nhân cùng một đơn giá.
        $priced = $this->units->resolvedInPrice($r['item_id']);
        $bookPrice = $priced['price'] ?? null;

        $ivtValue = $bookPrice === null || $ivtQty === null ? null : $ivtQty * $bookPrice;
        $bookValue = $bookPrice === null || $bookQty === null ? null : $bookQty * $bookPrice;

        // Every money figure now rests on that one purchase price, so a bad
        // cell in the book no longer costs a rounding error - it multiplies the
        // whole line. The book's own issue price is the cheapest cross-check
        // there is: both are weighted averages of the same purchases and should
        // sit close, so an order-of-magnitude gap means one of them is typed
        // wrong. Flagged, never corrected.
        $issuePrice = $bookQty !== null && $bookQty != 0.0 && $bookVal !== null ? $bookVal / $bookQty : null;
        $priceOdd = $bookPrice !== null && $issuePrice !== null && $issuePrice != 0.0
            && ($bookPrice / $issuePrice > 3.0 || $bookPrice / $issuePrice < 1 / 3);

        $pct = fn (?float $ivt, ?float $book): ?float => $ivt === null || $book === null || $book == 0.0
            ? null
            : ($ivt - $book) / abs($book) * 100;

        return $r + [
            // Null unless the book uses a different code for this item this
            // month — shown on screen so a substitution is never invisible.
            'tax_code' => $this->units->isAliased($r['item_id']) ? $this->units->taxCodeFor($r['item_id']) : null,
            'tax_unit' => $this->units->unitFor($r['item_id']),
            'tax_factor' => $this->units->factorFor($r['item_id'], $unitId),
            'tax_qty' => $ivtQty,

            // Tax-book side, in the tax book's own unit.
            'book_qty' => $bookQty,
            'book_end_qty' => $bookEndQty,

            // "152 cả tồn": what the book accounts for in total this period —
            // issued plus still on hand. Kept as its own column so the ratio
            // below is readable instead of being a formula nobody can check.
            'book_total_qty' => $bookQty === null || $bookEndQty === null ? null : $bookEndQty + $bookQty,

            'qty_ratio' => $this->ratio($bookQty, $ivtQty, $bookEndQty),
            'book_val' => $bookVal,
            'book_price' => $bookPrice,
            // Which month the price came from, so a borrowed one is visible.
            'price_month' => $priced['month'] ?? null,
            'issue_price' => $issuePrice,
            'price_odd' => $priceOdd,
            'book_in_qty' => $this->units->bookInQty($r['item_id']),

            // Both sides valued at that one price.
            'ivt_value' => $ivtValue,
            'book_value' => $bookValue,

            // Chênh lệch, quy ước IVT − thuế: dương = IVT nhiều hơn.
            'qty_pct' => $pct($ivtQty, $bookQty),
            'val_diff' => $ivtValue === null || $bookValue === null ? null : $ivtValue - $bookValue,
            'val_pct' => $pct($ivtValue, $bookValue),
        ];
    }

    /**
     * Tỉ lệ = (SL IVT + tồn cuối kỳ chia đều cho các tháng còn lại) ÷ SL 152.
     *
     * Ý của khách hàng: tồn cuối tháng không mất đi, nó phải được dùng hết
     * trong những tháng còn lại của kỳ. NHAIXANH tháng 2: IVT ghi dùng 118,366
     * nhưng sổ thuế còn tồn 264,429 — chia cho 4 tháng còn lại thì mỗi tháng phải
     * dùng thêm 66,107. Tử số vì thế là mức tiêu hao "đáng lẽ phải có", chia cho
     * mức sổ thuế ghi — ra 1,68, tức phải đẩy lên 1,68 lần mới tiêu hết tồn.
     *
     * Tháng 6 không còn tháng nào phía sau để phân bổ, nên phần tồn bị bỏ ra
     * khỏi tử số — chia cho 0 thì không ra con số nào đọc được.
     *
     * Null rather than 0 or INF whenever a term is missing or the divisor
     * vanishes — a ratio nobody can compute is not a ratio of zero.
     */
    private function ratio(?float $bookQty, ?float $ivtQty, ?float $bookEndQty): ?float
    {
        if ($bookQty === null || $ivtQty === null || $bookEndQty === null || $bookQty == 0.0) {
            return null;
        }

        $remaining = self::LAST_PERIOD_MONTH - $this->month;
        $spread = $remaining > 0 ? $bookEndQty / $remaining : 0.0;

        return ($ivtQty + $spread) / $bookQty;
    }

    /**
     * Types checked in the form. A first visit has no query string at all, and
     * defaults to everything except the internal transfer — the figure people
     * mean by "đã xuất" in this project.
     *
     * @param  array<int, string>  $labels
     * @return array<int, int>
     */
    private function types(Request $request, array $labels): array
    {
        if (! $request->has('types')) {
            return array_values(array_diff(array_keys($labels), [self::TRANSFER_TYPE]));
        }

        $wanted = array_map('intval', (array) $request->input('types', []));

        return array_values(array_intersect(array_keys($labels), $wanted));
    }

    /**
     * Ways the current filter departs from what the TK 152 book actually
     * covers. Anything listed here makes the "152" columns a comparison
     * between different things, which is worth saying before someone reads a
     * variance off them.
     *
     * @param  array<int, int>  $types
     * @param  array<int, string>  $warehouses
     * @return array<int, string>
     */
    private function basisMismatch(string $side, ?string $start, ?string $end, array $types, array $warehouses): array
    {
        $period = $this->units->period();
        $issues = [];

        // The month is picked, not typed, so this can only fire when the file
        // itself covers a different month than its name claims — worth catching
        // rather than trusting the filename.
        if ($period !== null && ($start !== $period['from'] || $end !== $period['to'])) {
            $issues[] = "File sổ thuế tự khai kỳ {$period['from']} → {$period['to']}, không khớp tháng đang chọn ({$start} → {$end}). Có thể file bị đặt sai tên.";
        }

        $shops = self::TAX_BOOK_WAREHOUSES;
        sort($shops);
        $picked = $warehouses;
        sort($picked);

        if ($picked !== $shops) {
            $issues[] = 'Sổ thuế là tổng của 4 cửa hàng ('.implode(', ', self::TAX_BOOK_WAREHOUSES)
                .'), còn bộ lọc đang lấy '.($warehouses === [] ? 'tất cả kho' : implode(', ', $warehouses)).'.';
        }

        if ($side === 'out' && in_array(self::TRANSFER_TYPE, $types, true)) {
            $issues[] = 'Đang tính cả loại 3 (điều chuyển nội bộ) — sổ thuế không có khoản này, cộng vào là đếm trùng.';
        }

        if ($side === 'in') {
            $issues[] = 'Cột "Xuất kho" của sổ thuế là hàng ĐI RA; màn hình này đang xem hàng ĐI VÀO, nên hai bên vốn không cùng một phép đo.';
        }

        return $issues;
    }

    /**
     * Every month the screens offer, each flagged with what actually exists for
     * it. A month with no crawl or no tax book is still listed — hiding it
     * would look like the month never happened.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $periods
     * @return array<int, array{month: int, label: string, has_data: bool, has_book: bool}>
     */
    private function months($periods): array
    {
        $crawled = $periods->map(fn ($p): string => substr($p->period_from, 0, 7))->all();
        $months = [];

        for ($m = self::FIRST_MONTH; $m <= self::LAST_MONTH; $m++) {
            $months[] = [
                'month' => $m,
                'label' => sprintf('Tháng %d/%d', $m, self::YEAR),
                'has_data' => in_array(sprintf('%04d-%02d', self::YEAR, $m), $crawled, true),
                'has_book' => TaxUnitCatalog::hasMonth($m),
            ];
        }

        return $months;
    }

    /**
     * The month to show: the one asked for, else the most recent one that has
     * both sides — showing a blank page by default helps nobody.
     *
     * @param  array<int, array{month: int, has_data: bool, has_book: bool}>  $months
     */
    private function month(mixed $requested, array $months): int
    {
        $wanted = is_numeric($requested) ? (int) $requested : 0;

        if ($wanted >= self::FIRST_MONTH && $wanted <= self::LAST_MONTH) {
            return $wanted;
        }

        $usable = array_filter($months, fn (array $m): bool => $m['has_data'] && $m['has_book']);

        return $usable === [] ? self::FIRST_MONTH : (int) end($usable)['month'];
    }
}
