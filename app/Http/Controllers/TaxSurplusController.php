<?php

namespace App\Http\Controllers;

use App\Services\TaxUnitCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * "Mua về mà không dùng hết" — the tax book on its own, rolled up over several
 * months.
 *
 * The other screens ask whether IVT and the tax book agree. This one ignores
 * IVT entirely: the question is what the accountant recorded as bought versus
 * what was recorded as issued, and the gap between them over half a year. An
 * item bought in bulk and barely touched is money sitting on a shelf, and
 * nothing about IVT changes that.
 *
 * Nhập − Xuất over a range equals closing − opening balance, so the screen
 * checks that identity rather than trusting the sum.
 */
class TaxSurplusController extends Controller
{
    private const YEAR = 2026;

    private const FIRST_MONTH = 1;

    private const LAST_MONTH = 9;

    public function __construct(private readonly TaxUnitCatalog $units) {}

    public function __invoke(Request $request): View
    {
        $available = array_values(array_filter(
            range(self::FIRST_MONTH, self::LAST_MONTH),
            fn (int $m): bool => TaxUnitCatalog::hasMonth($m)
        ));

        $from = $this->month($request->input('from'), $available[0] ?? self::FIRST_MONTH);
        $to = $this->month($request->input('to'), end($available) ?: self::FIRST_MONTH);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        // Value by default: quantities are counted in different units, so
        // ranking by them puts whatever is measured in grams on top regardless
        // of what it is worth. Money is the only figure comparable across the
        // whole catalogue.
        $sort = $request->input('sort') === 'qty' ? 'qty' : 'value';

        $rows = collect($this->units->aggregate($from, $to))
            ->map(fn (array $r, string $code): array => $r + [
                'code' => $code,
                // ĐK + Nhập − Xuất = CK. When it does not hold, the row is not
                // a story about surplus, it is a story about the file.
                'balanced' => abs($r['open_q'] + $r['in_q'] - $r['out_q'] - $r['end_q']) < 0.01,
            ])
            ->sortByDesc($sort === 'value' ? 'surplus_v' : 'surplus_q')
            ->values();

        $months = array_map(
            fn (int $m): array => [
                'month' => $m,
                'label' => sprintf('Tháng %d/%d', $m, self::YEAR),
                'has_book' => TaxUnitCatalog::hasMonth($m),
            ],
            range(self::FIRST_MONTH, self::LAST_MONTH)
        );

        return view('reports.surplus', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'sort' => $sort,
            'months' => $months,
            'covered' => array_values(array_filter($available, fn (int $m): bool => $m >= $from && $m <= $to)),
            'totals' => [
                'items' => $rows->count(),
                'in_v' => $rows->sum('in_v'),
                'out_v' => $rows->sum('out_v'),
                'surplus_v' => $rows->sum('surplus_v'),
                'end_v' => $rows->sum('end_v'),
                'unbalanced' => $rows->where('balanced', false)->count(),
                'mixed' => $rows->where('mixed_units', true)->count(),
            ],
        ]);
    }

    /**
     * @param  array<int, array{month: int}>|int  $fallback
     */
    private function month(mixed $value, int $fallback): int
    {
        $month = is_numeric($value) ? (int) $value : 0;

        return $month >= self::FIRST_MONTH && $month <= self::LAST_MONTH ? $month : $fallback;
    }
}
