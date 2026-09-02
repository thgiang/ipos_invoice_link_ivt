<?php

namespace App\Services;

use App\Console\Commands\DoiSoat152;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Throwable;

/**
 * The TK 152 tax book: what unit each item is counted in, how much was issued,
 * and the factor that brings an IVT quantity into that unit.
 *
 * Two shapes of the same book are in circulation, so the class carries both:
 *
 *  - **One file per month** (`Bang_152_thang_1.xlsx` …) — what the report
 *    screens use. The accountant re-exports these as figures get corrected, so
 *    they are read on **every request** and never cached to disk: a stale
 *    number that looks current is worse than a slow page, and a month's book is
 *    only ~110 rows / ~50 ms to read.
 *  - **One file for the whole period** (v0.4) — the default of
 *    `app:doi-soat-152`, whose reconciliation spans six months at once.
 *
 * An item the book does not carry has no "đơn vị 152" at all — that is a real
 * absence, not a lookup failure, and is reported as such rather than guessed at.
 */
class TaxUnitCatalog
{
    /**
     * The whole-period book. v0.4 supersedes sheet "152" of
     * bao_cao_tai_chinh_6_thang_dau_nam_2026.xlsx; the layout is identical.
     *
     * Public because app:doi-soat-152 defaults to the same workbook — one
     * constant to bump when the accountant sends the next revision, rather
     * than a filename to chase through the command and the report screens.
     */
    public const WORKBOOK = 'Tong_hop_ton_kho - v0.4 - 2026.08.08.xlsx';

    public const SHEET = 'TỔNG HỢP TỒN KHO';

    /**
     * Monthly books. The filename carries no year, so the year is only known
     * from the header line inside the file.
     */
    public const MONTHLY_PATTERN = 'Bang_152_thang_%d.xlsx';

    /**
     * Items the tax book splits across more than one code while IVT keeps a
     * single one, mapped per month: IVT item_id => [tháng => mã bên sổ thuế].
     *
     * TRADEN is the case in hand — IVT books everything under TRADEN (in TUI),
     * whereas the accountant used TRADEN2 (Túi) early in the year and TRADEN
     * (kg) later. Which code is the live one in a given month is a bookkeeping
     * fact nobody can derive from the numbers, so it is written down.
     *
     * ⚠ Bản tạm khách hàng chốt 2026-08-22, sẽ sửa lại sau. Note that both
     * codes carry figures in every month, so mapping to one leaves the other
     * unmatched — T3 in particular still has 24,2 triệu on TRADEN2.
     *
     * @var array<string, array<int, string>>
     */
    public const ITEM_ALIASES = [
        'TRADEN' => [
            1 => 'TRADEN2',
            2 => 'TRADEN2',
            3 => 'TRADEN',
            4 => 'TRADEN',
            5 => 'TRADEN',
            6 => 'TRADEN',
        ],
    ];

    private string $source;

    private string $label;

    /** Which month's book is loaded, when it is a monthly one. */
    private ?int $month = null;

    /** @var array<int, array<string, array<string, mixed>>> other months, read on demand for the price fallback */
    private array $monthlyItems = [];

    /** @var array<string, array{name: string, unit: string, qty: float, val: float, end_qty: float, in_qty: float, in_val: float}>|null */
    private ?array $items = null;

    /** @var array{from: string, to: string}|null */
    private ?array $period = null;

    /** @var array<string, float|null> */
    private array $factors = [];

    private ?string $loadError = null;

    public function __construct(private readonly UnitConversionCatalog $units)
    {
        $this->source = storage_path(self::WORKBOOK);
        $this->label = self::WORKBOOK;
    }

    /**
     * Read from the book for one month instead of the whole-period one.
     *
     * Returns false when that month has no file yet — the caller then shows an
     * empty "152" side rather than silently comparing against another month.
     */
    public function useMonth(int $month): bool
    {
        $path = storage_path(sprintf(self::MONTHLY_PATTERN, $month));

        $this->source = $path;
        $this->label = basename($path);
        $this->month = $month;
        $this->items = null;
        $this->monthlyItems = [];
        $this->period = null;
        $this->factors = [];
        $this->loadError = null;

        return is_file($path);
    }

    public static function hasMonth(int $month): bool
    {
        return is_file(storage_path(sprintf(self::MONTHLY_PATTERN, $month)));
    }

    /**
     * When the book on disk was last written. Shown on screen so an accountant
     * who has just re-exported can tell whether the page picked the new file up.
     */
    public function sourceUpdatedAt(): ?int
    {
        return is_file($this->source) ? filemtime($this->source) : null;
    }

    /**
     * The code this IVT item is booked under in the tax book this month —
     * usually itself, occasionally a different one (see ITEM_ALIASES).
     */
    public function taxCodeFor(string $itemId, ?int $month = null): string
    {
        $month ??= $this->month;

        if ($month === null) {
            return $itemId;
        }

        return self::ITEM_ALIASES[$itemId][$month] ?? $itemId;
    }

    /**
     * True when this item is being read off a different code in the tax book,
     * which the screens surface rather than quietly substitute.
     */
    public function isAliased(string $itemId): bool
    {
        return $this->taxCodeFor($itemId) !== $itemId;
    }

    /**
     * The tax book's unit for an item, or null when the item is not in it.
     */
    public function unitFor(string $itemId): ?string
    {
        return $this->load()[$this->taxCodeFor($itemId)]['unit'] ?? null;
    }

    /**
     * Quantity the tax book issued for an item, in the tax book's own unit
     * (column O). Null when the item is not on the books at all — which is a
     * different statement from "issued nothing", so it is not folded into 0.
     */
    public function bookQty(string $itemId): ?float
    {
        return $this->load()[$this->taxCodeFor($itemId)]['qty'] ?? null;
    }

    /**
     * Closing balance on the books for an item (column Q), in the tax book's
     * unit. Same caveat as bookQty: null means "not on the books at all".
     */
    public function bookEndQty(string $itemId): ?float
    {
        return $this->load()[$this->taxCodeFor($itemId)]['end_qty'] ?? null;
    }

    /**
     * Purchase price per tax unit in the selected month: value received ÷
     * quantity received (L ÷ K). Null when nothing was received.
     */
    public function bookInPrice(string $itemId): ?float
    {
        return $this->priceIn($this->month ?? 0, $itemId, $this->unitFor($itemId));
    }

    /**
     * The purchase price to value an item at, falling back to the nearest other
     * month when nothing was bought in the selected one.
     *
     * Roughly a fifth of the codes go a month without a purchase, so insisting
     * on the current month would leave a fifth of the table unvalued. Nearest
     * earlier month first — that is what the stock on hand was actually bought
     * at — then later months, which is all January can fall back on.
     *
     * A borrowed price is only accepted when that month's book counts the item
     * in the same unit: TRADEN alone is booked in Túi early in the year and in
     * kg later, and a price per túi is not a price per kg.
     *
     * @return array{price: float, month: int}|null
     */
    public function resolvedInPrice(string $itemId): ?array
    {
        if ($this->month === null) {
            $price = $this->bookInPrice($itemId);

            return $price === null ? null : ['price' => $price, 'month' => 0];
        }

        $unit = $this->unitFor($itemId);

        if ($unit === null) {
            return null;
        }

        foreach ($this->searchOrder($this->month) as $month) {
            $price = $this->priceIn($month, $itemId, $unit);

            if ($price !== null) {
                return ['price' => $price, 'month' => $month];
            }
        }

        return null;
    }

    /**
     * Months to try, nearest-earlier first, then later.
     *
     * @return array<int, int>
     */
    private function searchOrder(int $month): array
    {
        $order = [$month];

        for ($m = $month - 1; $m >= 1; $m--) {
            $order[] = $m;
        }

        for ($m = $month + 1; $m <= 12; $m++) {
            $order[] = $m;
        }

        return array_values(array_filter($order, fn (int $m): bool => self::hasMonth($m)));
    }

    /**
     * Purchase price in one month's book, but only when that book counts the
     * item in $unit — a price per a different unit is not a price at all here.
     */
    private function priceIn(int $month, string $itemId, ?string $unit): ?float
    {
        $items = $month === ($this->month ?? 0) ? $this->load() : $this->itemsForMonth($month);
        $item = $items[$this->taxCodeFor($itemId, $month ?: null)] ?? null;

        if ($item === null || $item['in_qty'] == 0.0) {
            return null;
        }

        if ($unit !== null && $item['unit'] !== $unit) {
            return null;
        }

        return $item['in_val'] / $item['in_qty'];
    }

    /**
     * Every month between $from and $to rolled into one row per item.
     *
     * Answers a different question from the monthly screens: not "do the two
     * systems agree this month" but "what has been bought over the half-year
     * and never used". Only the tax book takes part — IVT has no say in what
     * the accountant recorded as received.
     *
     * A code counted in more than one unit across the range cannot be summed;
     * its units are listed so the row can be marked unusable rather than
     * silently adding kilos to bags.
     *
     * @return array<string, array<string, mixed>>
     */
    public function aggregate(int $from, int $to): array
    {
        $totals = [];

        for ($month = $from; $month <= $to; $month++) {
            if (! self::hasMonth($month)) {
                continue;
            }

            foreach ($this->itemsForMonth($month) as $code => $item) {
                if (! isset($totals[$code])) {
                    $totals[$code] = [
                        'name' => $item['name'],
                        'unit' => $item['unit'],
                        'units' => [],
                        'months' => 0,
                        // Opening balance of the first month the code appears
                        // in: the stock it started the window with.
                        'open_q' => $item['open_qty'],
                        'open_v' => $item['open_val'],
                        'in_q' => 0.0, 'in_v' => 0.0,
                        'out_q' => 0.0, 'out_v' => 0.0,
                        'end_q' => 0.0, 'end_v' => 0.0,
                        'first_month' => $month,
                    ];
                }

                $totals[$code]['units'][$item['unit']] = true;
                $totals[$code]['unit'] = $item['unit'];
                $totals[$code]['months']++;
                $totals[$code]['in_q'] += $item['in_qty'];
                $totals[$code]['in_v'] += $item['in_val'];
                $totals[$code]['out_q'] += $item['qty'];
                $totals[$code]['out_v'] += $item['val'];
                // Closing balance of the last month seen, not a sum.
                $totals[$code]['end_q'] = $item['end_qty'];
                $totals[$code]['end_v'] = $item['end_val'];
                $totals[$code]['last_month'] = $month;
            }
        }

        foreach ($totals as $code => $row) {
            $totals[$code]['units'] = array_keys($row['units']);
            $totals[$code]['mixed_units'] = count($totals[$code]['units']) > 1;
            $totals[$code]['surplus_q'] = $row['in_q'] - $row['out_q'];
            $totals[$code]['surplus_v'] = $row['in_v'] - $row['out_v'];
            // Share of what was bought that actually left the shelf.
            $totals[$code]['used_pct'] = $row['in_q'] == 0.0 ? null : $row['out_q'] / $row['in_q'] * 100;
        }

        return $totals;
    }

    /**
     * Another month's book, read live and kept only for this request.
     *
     * @return array<string, array<string, mixed>>
     */
    private function itemsForMonth(int $month): array
    {
        if (isset($this->monthlyItems[$month])) {
            return $this->monthlyItems[$month];
        }

        $path = storage_path(sprintf(self::MONTHLY_PATTERN, $month));

        return $this->monthlyItems[$month] = is_file($path) ? $this->read($path) : [];
    }

    /**
     * Quantity received in the period (column K), in the tax book's unit.
     */
    public function bookInQty(string $itemId): ?float
    {
        return $this->load()[$this->taxCodeFor($itemId)]['in_qty'] ?? null;
    }

    /**
     * Value the tax book issued for an item (column P), same caveat as bookQty.
     */
    public function bookValue(string $itemId): ?float
    {
        return $this->load()[$this->taxCodeFor($itemId)]['val'] ?? null;
    }

    /**
     * How many IVT units make up one tax unit — null when the item is absent
     * from the tax book, or present but with no confirmed packaging size.
     *
     * Mirrors DoiSoat152::conversionFactor(): a hand-confirmed factor from the
     * customer always beats anything the IVT catalog infers.
     */
    public function factorFor(string $itemId, string $ivtUnit): ?float
    {
        $key = $itemId.'|'.$ivtUnit;

        if (array_key_exists($key, $this->factors)) {
            return $this->factors[$key];
        }

        // Unit from whichever code the book uses this month; the conversion
        // graph and the hand-confirmed factors stay keyed by the IVT item,
        // because they describe that physical product, not a bookkeeping code.
        $taxUnit = $this->unitFor($itemId);

        if ($taxUnit === null) {
            return $this->factors[$key] = null;
        }

        $manual = DoiSoat152::manualFactor($itemId, $ivtUnit, $this->units);

        if ($manual !== null) {
            return $this->factors[$key] = $manual;
        }

        // The catalog refreshes itself from IVT when its 24h cache is stale and
        // throws if that call fails. A command may die on that; a report screen
        // must not — it drops the converted column and says why.
        try {
            return $this->factors[$key] = $this->units->factor($itemId, $taxUnit, $ivtUnit);
        } catch (Throwable $e) {
            $this->loadError ??= 'Không tải được bảng quy đổi đơn vị từ IVT: '.$e->getMessage();

            return $this->factors[$key] = null;
        }
    }

    /**
     * Quantity restated in the tax book's unit, or null when no factor is known.
     */
    public function convert(string $itemId, string $ivtUnit, float $qty): ?float
    {
        $factor = $this->factorFor($itemId, $ivtUnit);

        return $factor === null || $factor == 0.0 ? null : $qty / $factor;
    }

    /**
     * The period the workbook itself declares, read off its header line.
     * Comparing IVT figures from any other period against these columns is
     * meaningless, so the screens check it rather than assume.
     *
     * @return array{from: string, to: string}|null
     */
    public function period(): ?array
    {
        $this->load();

        return $this->period;
    }

    /**
     * Why the tax units are missing, when they are — so the screen can say the
     * workbook is unreadable instead of silently showing an empty column.
     */
    public function loadError(): ?string
    {
        $this->load();

        $parts = array_filter([$this->loadError, $this->units->staleWarning()]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    public function workbookName(): string
    {
        return $this->label;
    }

    /**
     * @return array<string, array{name: string, unit: string, qty: float, val: float, end_qty: float, in_qty: float, in_val: float}>
     */
    private function load(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        if (! is_file($this->source)) {
            $this->loadError = 'Không tìm thấy '.$this->label.' trong storage/.';

            return $this->items = [];
        }

        return $this->items = $this->read($this->source, true);
    }

    /**
     * Parse one book. $primary marks the file the page is actually about, whose
     * header sets the period and whose read errors are worth showing.
     *
     * @return array<string, array<string, mixed>>
     */
    private function read(string $path, bool $primary = false): array
    {
        try {
            $reader = new XlsxReader;
            $reader->setReadDataOnly(true);
            // By index, not by name: the monthly exports and the whole-period
            // book happen to agree on a sheet name today, but nothing enforces
            // that and a renamed sheet should not empty the whole comparison.
            $worksheet = $reader->load($path)->getSheet(0);
        } catch (Throwable $e) {
            if ($primary) {
                $this->loadError = 'Không đọc được '.$this->label.': '.$e->getMessage();
            }

            return [];
        }

        $items = [];

        // Same layout as DoiSoat152::readTaxSheet(): 5 header rows, then
        // A Tên kho | B Mã hàng | C Tên hàng | D ĐVT, ending with a total row.
        foreach ($worksheet->toArray(null, true, false, false) as $i => $row) {
            if ($i < 5) {
                if ($primary) {
                    $this->readPeriod((string) ($row[0] ?? ''));
                }

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
                // O/P — "Xuất kho": the columns the reconciliation compares.
                'qty' => (float) ($row[14] ?? 0),
                'val' => (float) ($row[15] ?? 0),
                // Q/R — "Cuối kỳ": what is left on the books.
                'end_qty' => (float) ($row[16] ?? 0),
                'end_val' => (float) ($row[17] ?? 0),
                // E/F — "Đầu kỳ", read only for the first month of a range.
                'open_qty' => (float) ($row[4] ?? 0),
                'open_val' => (float) ($row[5] ?? 0),
                // K/L — "Nhập kho": everything received. The unit price is
                // taken from here rather than from the issue columns or from
                // IVT, because a purchase price is what the item actually cost;
                // an issue price is a weighted average the opening balance
                // drags around.
                'in_qty' => (float) ($row[10] ?? 0),
                'in_val' => (float) ($row[11] ?? 0),
            ];
        }

        return $items;
    }

    /**
     * The two header wordings in use: the whole-period book states a date range
     * ("Từ ngày 01/01/2026 đến ngày 30/06/2026"), the monthly ones name the
     * month ("Kho: Bếp Trung Liệt, Tháng 1 năm 2026").
     */
    private function readPeriod(string $header): void
    {
        if (preg_match('#(\d{2})/(\d{2})/(\d{4}).*?(\d{2})/(\d{2})/(\d{4})#u', $header, $m) === 1) {
            $this->period = ['from' => "{$m[3]}-{$m[2]}-{$m[1]}", 'to' => "{$m[6]}-{$m[5]}-{$m[4]}"];

            return;
        }

        if (preg_match('#Tháng\s*(\d{1,2})\s*năm\s*(\d{4})#ui', $header, $m) === 1) {
            $from = sprintf('%04d-%02d-01', (int) $m[2], (int) $m[1]);
            $this->period = ['from' => $from, 'to' => date('Y-m-t', strtotime($from))];
        }
    }
}
