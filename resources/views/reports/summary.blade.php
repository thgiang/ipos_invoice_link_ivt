@php
    $num = fn ($v, $d = 0) => number_format((float) $v, $d, ',', '.');
    $qty = function ($v) {
        $v = (float) $v;

        return number_format($v, abs($v - round($v)) < 0.0005 ? 0 : 3, ',', '.');
    };

    // A tax-book cell is empty for a reason: the item is not on the books at
    // all. Saying so beats printing a 0 that reads as "issued nothing".
    $book = fn (?float $v, callable $fmt) => $v === null
        ? '<span class="sub" title="Mã này không có trong sổ thuế TK 152">—</span>'
        : $fmt($v);

    // Gap colouring is a reading aid, not a verdict: green only means small.
    $pct = function (?float $v) use ($num) {
        if ($v === null) {
            return '<span class="sub">—</span>';
        }

        $a = abs($v);
        $cls = $a < 1 ? 'ok' : ($a < 10 ? 'warn' : 'bad');

        return '<span class="'.$cls.'">'.($v > 0 ? '+' : '').$num($v, 1).'%</span>';
    };
@endphp
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
<style>
    :root {
        --bg: #f5f6f8; --card: #fff; --line: #e2e5ea; --text: #1c2024;
        --muted: #6b7280; --accent: #1d4ed8; --warn: #92400e; --warn-bg: #fef3c7;
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text);
           font: 14px/1.45 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
    a { color: var(--accent); }
    /* Full width on purpose: the comparison table is 17 columns wide and
       centring it in a fixed column would force horizontal scrolling on
       screens that have the room to show everything. */
    .wrap { margin: 0; padding: 18px 22px; }
    nav { display: flex; gap: 8px; margin-bottom: 16px; }
    nav a { padding: 9px 18px; border-radius: 8px; background: var(--card);
            border: 1px solid var(--line); text-decoration: none; font-weight: 600; }
    nav a.on { background: var(--accent); border-color: var(--accent); color: #fff; }
    .card { background: var(--card); border: 1px solid var(--line);
            border-radius: 10px; padding: 16px; margin-bottom: 16px; }
    h1 { font-size: 19px; margin: 0 0 14px; }
    .filters { display: flex; flex-wrap: wrap; gap: 22px; align-items: flex-start; }
    fieldset { border: 0; padding: 0; margin: 0; }
    legend, .lbl { font-size: 12px; font-weight: 700; text-transform: uppercase;
                   letter-spacing: .04em; color: var(--muted); margin-bottom: 7px; display: block; }
    label.chk { display: block; padding: 2px 0; cursor: pointer; white-space: nowrap; }
    input[type=date], select { padding: 7px 9px; border: 1px solid var(--line);
                               border-radius: 6px; font: inherit; background: #fff; }
    select[multiple] { min-width: 220px; height: 132px; }
    button { padding: 9px 22px; border: 0; border-radius: 6px; background: var(--accent);
             color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
    .reset { display: inline-block; margin-left: 10px; font-size: 13px; }
    .note { background: var(--warn-bg); color: var(--warn); border-radius: 6px;
            padding: 9px 12px; margin-top: 12px; font-size: 13px; }
    .meta { color: var(--muted); font-size: 12.5px; margin-top: 10px; }
    .stats { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
    .stat { flex: 1 1 200px; background: var(--card); border: 1px solid var(--line);
            border-radius: 10px; padding: 12px 16px; }
    .stat span { display: block; font-size: 12px; color: var(--muted); }
    .stat b { font-size: 21px; font-variant-numeric: tabular-nums; }
    .scroll { overflow: auto; }
    /* The table scrolls inside its own viewport instead of growing the page:
       with 165 rows an overflow-x bar would sit below the last row, so it is
       only reachable after scrolling past the whole table. Capping the height
       pins that bar to the bottom of the screen where it is always in reach,
       and keeps the header visible while scrolling sideways. */
    .scroll.pane { max-height: calc(100vh - 46px); }
    table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
    th, td { padding: 7px 10px; border-bottom: 1px solid var(--line); text-align: left; }
    thead th { position: sticky; background: #eef1f5; font-size: 11.5px; z-index: 2;
               text-transform: uppercase; letter-spacing: .03em; color: #41474f; }
    /* Two header rows, so the second must be offset by the height of the first
       — which is why that height is pinned rather than left to the content. */
    thead tr:first-child th { top: 0; height: 30px; }
    thead tr:nth-child(2) th { top: 30px; }
    th.n, td.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    tbody tr:hover { background: #f8fafc; }
    tfoot td { font-weight: 700; background: #eef1f5; border-top: 2px solid #cbd2da; }
    .sub { color: var(--muted); font-size: 11.5px; }
    .miss { color: #b45309; }
    .tag { display: inline-block; background: #d1fae5; color: #065f46; border-radius: 4px;
           padding: 1px 6px; font-size: 11px; white-space: nowrap; }
    .bad-tag { background: #fee2e2; color: #991b1b; }
    .grp { border-left: 2px solid #cbd2da; }
    th.c { text-align: center; background: #e3e8ef; }
    .ok { color: #15803d; }
    .warn { color: #b45309; }
    .bad { color: #b91c1c; font-weight: 600; }
    .empty { padding: 40px; text-align: center; color: var(--muted); }
    details summary { cursor: pointer; font-size: 15px; }
    /* Prose stays in a readable column even though the page does not. */
    details p { font-size: 13px; line-height: 1.6; color: #374151; max-width: 95ch; }
    details .warnbox { background: var(--warn-bg); color: var(--warn); border-radius: 6px; padding: 10px 13px; }
    table.doc { margin: 4px 0 14px; font-size: 13px; }
    table.doc td:first-child, table.doc th:first-child { white-space: nowrap; }
    table.doc td:nth-child(2) { white-space: nowrap; }
    code { background: #eef1f5; border-radius: 4px; padding: 1px 5px; font-size: 12.5px; }
    pre.sql { background: #1f2430; color: #e6e9ef; border-radius: 8px; padding: 13px 15px;
              overflow-x: auto; font-size: 12.5px; line-height: 1.5; }
</style>
</head>
<body>
<div class="wrap">

    <nav>
        <a href="{{ route('reports.stock-out') }}" class="{{ $side === 'out' ? 'on' : '' }}">Xuất kho</a>
        <a href="{{ route('reports.stock-in') }}" class="{{ $side === 'in' ? 'on' : '' }}">Nhập kho</a>
        <a href="{{ route('reports.surplus') }}">Nhập nhiều — dùng ít</a>
    </nav>

    <div class="card">
        <h1>{{ $title }}</h1>

        <form method="get" class="filters">
            <fieldset>
                <legend>{{ $typeLabel }}</legend>
                @foreach ($typeLabels as $value => $label)
                    <label class="chk">
                        <input type="checkbox" name="types[]" value="{{ $value }}"
                               @checked(in_array($value, $selectedTypes, true))>
                        {{ $value }}. {{ $label }}
                        @if ($value === $transferType)
                            <span class="sub">(đếm 2 lần)</span>
                        @endif
                    </label>
                @endforeach
            </fieldset>

            <fieldset>
                <legend>Tháng</legend>
                <select name="month" style="min-width:170px">
                    @foreach ($months as $m)
                        <option value="{{ $m['month'] }}" @selected($m['month'] === $month)>
                            {{ $m['label'] }}{{ $m['has_data'] && $m['has_book'] ? '' : ' — '.trim((! $m['has_data'] ? 'chưa crawl IVT' : '').(! $m['has_data'] && ! $m['has_book'] ? ', ' : '').(! $m['has_book'] ? 'chưa có bảng 152' : '')) }}
                        </option>
                    @endforeach
                </select>
                <div class="meta">{{ $startDate }} → {{ $endDate }}</div>
            </fieldset>

            <fieldset>
                <legend>{{ $warehouseLabel }}</legend>
                <select name="warehouses[]" multiple>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->code }}" @selected(in_array($w->code, $selectedWarehouses, true))>
                            {{ $w->name }}
                        </option>
                    @endforeach
                </select>
                <div class="meta">Bỏ trống = tất cả kho</div>
            </fieldset>

            <fieldset>
                <legend>Công thức chế biến</legend>
                <label class="chk">
                    <input type="checkbox" name="explode" value="1" @checked($explode)>
                    <b>Bung công thức</b>
                </label>
                <div class="meta" style="max-width:250px">
                    Thay món chế biến bằng nguyên liệu thô của nó
                    (COT_TS_KHOAIMON → NHAIXANH + SUADAC + BOTSUA), bung đệ quy đến hết.
                </div>
            </fieldset>

            <fieldset style="align-self:flex-end;padding-bottom:2px">
                <button type="submit">Lọc</button>
                <a class="reset" href="{{ route($side === 'out' ? 'reports.stock-out' : 'reports.stock-in') }}">Đặt lại</a>
            </fieldset>
        </form>

        <div class="meta">
            Sổ thuế đối chiếu: <b>{{ $taxWorkbook }}</b>
            @if ($bookUpdatedAt)
                <span class="sub">— file lưu lúc {{ date('d/m/Y H:i', $bookUpdatedAt) }}, đọc mới mỗi lần tải trang</span>
            @endif
        </div>

        @if ($includedPeriods->isEmpty())
            <div class="note">
                <b>Chưa có dữ liệu IVT cho {{ $months[$month - 1]['label'] ?? 'tháng này' }}.</b>
                Chạy <code>php artisan stock-{{ $side === 'out' ? 'out' : 'in' }}-summary:crawl
                --start_date={{ $startDate }} --end_date={{ $endDate }}</code> rồi tải lại.
            </div>
        @endif

        @if (! $bookExists)
            <div class="note">
                <b>Chưa có bảng 152 cho tháng này.</b> Cần file
                <code>storage/{{ sprintf(\App\Services\TaxUnitCatalog::MONTHLY_PATTERN, $month) }}</code>.
                Các cột “152” sẽ trống cho tới khi có file.
            </div>
        @endif

        @if ($taxError && $bookExists)
            <div class="note">Lưu ý về cột quy đổi: {{ $taxError }}</div>
        @endif

        @if ($explode && $explosion['count'] > 0)
            <div class="meta" style="background:#ecfdf5;color:#065f46;border-radius:6px;padding:9px 12px">
                Đã bung <b>{{ $num($explosion['count']) }}</b> mã chế biến thành nguyên liệu thô,
                chuyển <b>{{ $num($explosion['cost']) }} đ</b> giá vốn sang các nguyên liệu.
                Tổng tiền không đổi — chỉ đổi chỗ.
                <span class="sub">{{ implode(', ', array_slice($explosion['items'], 0, 12)) }}@if (count($explosion['items']) > 12) … @endif</span>
            </div>
        @endif

        @if ($doubleCount)
            <div class="note" style="background:#fee2e2;color:#991b1b">
                <b>Đang đếm trùng.</b> Bung công thức trong khi vẫn tích <b>loại 6 (XSD — xuất sử dụng)</b>
                thì cùng một lượng nguyên liệu bị cộng hai lần: một lần khi cửa hàng đốt nó để làm topping (XSD),
                một lần nữa khi topping đó bán ra ở loại 1 và bị bung ngược lại thành nguyên liệu.
                Trên dữ liệu 2026, riêng XSD tại 4 cửa hàng là <b>1.265.784.701 đ</b> — phần lớn chính là
                NHAIXANH, HOPPHOMAI, BOTSUA, TRADEN, SUADAC.
                <br>
                <a href="{{ request()->fullUrlWithQuery(['types' => array_values(array_diff($selectedTypes, [6]))]) }}">
                    <b>→ Bỏ loại 6 để hết đếm trùng</b>
                </a>
                — hoặc bỏ tích “Bung công thức” và để món chế biến đứng nguyên (đây là cách
                <code>app:doi-soat-152</code> đang làm).
            </div>
        @endif

        @if ($explosion['issues'] !== [])
            <div class="note">
                <b>Không bung được {{ count($explosion['issues']) }} trường hợp</b>, các mã này giữ nguyên là món chế biến:
                <ul style="margin:6px 0 0 18px;padding:0">
                    @foreach ($explosion['issues'] as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($basisMismatch !== [])
            <div class="note">
                <b>Các cột “152” đang so lệch cơ sở</b> — sổ thuế
                @if ($taxPeriod)(kỳ {{ $taxPeriod['from'] }} → {{ $taxPeriod['to'] }}, 4 cửa hàng, cột “Xuất kho”)@endif
                được lập trên một phạm vi khác với bộ lọc hiện tại:
                <ul style="margin:6px 0 0 18px;padding:0">
                    @foreach ($basisMismatch as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
                Con số chênh lệch bên dưới vì thế <b>không đọc như một kết luận đối soát</b> được.
                @if ($side === 'out' && $taxPeriod)
                    <a href="{{ route('reports.stock-out').'?'.http_build_query([
                        'month' => $month,
                        'types' => array_values(array_diff(array_keys($typeLabels), [$transferType])),
                        'warehouses' => $taxBookWarehouses,
                    ] + ($explode ? ['explode' => 1] : [])) }}"><b>→ Đặt về đúng cơ sở sổ thuế 152</b></a>
                @endif
            </div>
        @endif
    </div>

    <div class="stats">
        <div class="stat"><span>Số mặt hàng</span><b>{{ $num($rows->count()) }}</b>
            <span>{{ $num($totals['compared']) }} mã có ở cả 152</span></div>
        <div class="stat"><span>SL IVT × đơn giá nhập 152 — {{ $num($totals['compared']) }} mã</span>
            <b>{{ $num($totals['cost_compared']) }}</b>
            <span>chỉ số lượng là của IVT, giá lấy từ 152</span></div>
        <div class="stat"><span>SL 152 × đơn giá nhập 152 (cùng {{ $num($totals['compared']) }} mã)</span>
            <b>{{ $num($totals['book_val']) }}</b>
            <span>cột P nguyên văn: {{ $num($totals['book_raw']) }}</span></div>
        <div class="stat"><span>Chênh lệch (IVT − 152) — thuần số lượng</span>
            <b class="{{ abs($totals['val_pct'] ?? 0) < 1 ? 'ok' : (abs($totals['val_pct'] ?? 0) < 10 ? 'warn' : 'bad') }}">{{ $num($totals['val_diff']) }}</b>
            <span>{!! $pct($totals['val_pct']) !!} so với sổ thuế</span></div>
    </div>

    <div class="card scroll pane" style="padding:0">
        @if ($rows->isEmpty())
            <div class="empty">
                @if ($selectedTypes === [])
                    Chưa chọn loại nào.
                @else
                    Không có dữ liệu cho bộ lọc này.
                @endif
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th rowspan="2">#</th>
                        <th rowspan="2">Mã hàng</th>
                        <th rowspan="2">Tên hàng</th>
                        <th colspan="3" class="grp c">Đơn vị</th>
                        <th colspan="5" class="grp c">Số lượng</th>
                        <th colspan="1" class="grp c" title="Giá trị nhập kho ÷ Số lượng nhập kho, lấy từ file 152">Đơn giá</th>
                        <th colspan="4" class="grp c" title="Số lượng mỗi bên × đơn giá nhập 152">Thành tiền — định giá theo 152</th>
                        <th rowspan="2" class="n grp">152 gốc<br><span class="sub">cột P</span></th>
                    </tr>
                    <tr>
                        <th class="grp">ĐVT gốc</th>
                        <th>ĐVT 152</th>
                        <th class="n">Hệ số</th>
                        <th class="n grp">gốc</th>
                        <th class="n">IVT</th>
                        <th class="n">152</th>
                        <th class="n" title="Số lượng cuối kỳ + Số lượng xuất kho, cả hai lấy ở file 152">152 cả tồn</th>
                        <th class="n" title="(SL IVT + tồn cuối kỳ chia đều cho {{ max(6 - $month, 0) }} tháng còn lại) ÷ SL 152">Tỉ lệ</th>
                        <th class="n grp">152 nhập</th>
                        <th class="n grp">IVT</th>
                        <th class="n">152</th>
                        <th class="n">&Delta;</th>
                        <th class="n">&Delta; %</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $r)
                        <tr>
                            <td class="sub">{{ $i + 1 }}</td>
                            <td><b>{{ $r['item_id'] }}</b></td>
                            <td>{{ $r['item_name'] }}<br><span class="sub">{{ $r['item_class_name'] }}</span>
                                @if (($r['from_recipe'] ?? 0) > 0)
                                    <span class="tag" title="Phần giá trị chuyển sang từ việc bung công thức món chế biến">bung CT +{{ $num($r['from_recipe']) }}</span>
                                @endif
                            </td>

                            <td class="grp">{{ $r['unit_id'] }} <span class="sub">{{ $r['unit_name'] }}</span></td>
                            <td>
                                @if ($r['tax_unit'] !== null)
                                    {{ $r['tax_unit'] }}
                                    @if ($r['tax_code'])
                                        <br><span class="tag" title="Sổ thuế tháng này ghi mặt hàng này dưới mã khác">mã 152: {{ $r['tax_code'] }}</span>
                                    @endif
                                @else
                                    <span class="sub" title="Mã này không có trong sổ thuế TK 152">không có ở 152</span>
                                @endif
                            </td>
                            <td class="n sub">
                                @if ($r['tax_factor'] !== null)
                                    1 {{ $r['tax_unit'] }} = {{ $qty($r['tax_factor']) }} {{ $r['unit_id'] }}
                                @endif
                            </td>

                            <td class="n grp">{{ $qty($r['qty']) }}</td>
                            <td class="n">
                                @if ($r['tax_qty'] !== null)
                                    {{ $qty($r['tax_qty']) }}
                                @elseif ($r['tax_unit'] !== null)
                                    <span class="miss" title="Chưa biết 1 {{ $r['tax_unit'] }} (sổ thuế) bằng bao nhiêu {{ $r['unit_id'] }} (IVT). Hỏi khách hàng rồi điền vào DoiSoat152::ITEM_UNIT_FACTORS — xem mục Công thức tính ở cuối trang.">chờ quy đổi<br><span class="sub">1 {{ $r['tax_unit'] }} = ? {{ $r['unit_id'] }}</span></span>
                                @else
                                    <span class="sub">—</span>
                                @endif
                            </td>
                            <td class="n">{!! $book($r['book_qty'], $qty) !!}</td>
                            <td class="n" @if ($r['book_end_qty'] !== null) title="{{ $qty($r['book_end_qty']) }} cuối kỳ + {{ $qty($r['book_qty']) }} xuất kho" @endif>{!! $book($r['book_total_qty'], $qty) !!}</td>
                            <td class="n" @if ($r['qty_ratio'] !== null) title="({{ $qty($r['tax_qty']) }} IVT{{ 6 - $month > 0 ? ' + '.$qty($r['book_end_qty']).' tồn ÷ '.(6 - $month).' tháng = '.$qty($r['book_end_qty'] / (6 - $month)) : ' — tháng 6 không phân bổ tồn' }}) ÷ {{ $qty($r['book_qty']) }} (152)" @endif>
                                @if ($r['qty_ratio'] === null)
                                    <span class="sub">—</span>
                                @else
                                    {{ $num($r['qty_ratio'], 2) }}
                                @endif
                            </td>

                            <td class="n grp" @if ($r['book_price'] !== null) title="{{ $r['price_month'] === $month ? 'nhập '.$qty($r['book_in_qty']).' '.$r['tax_unit'].' trong tháng này' : 'tháng này không nhập, lấy giá nhập của tháng '.$r['price_month'] }}" @endif>
                                {!! $book($r['book_price'], $num) !!}
                                @if ($r['book_price'] !== null && $r['price_month'] !== $month)
                                    <br><span class="tag">T{{ $r['price_month'] }}</span>
                                @endif
                                @if ($r['price_odd'])
                                    <br><span class="tag bad-tag" title="Giá nhập {{ $num($r['book_price']) }} lệch quá xa giá xuất {{ $num($r['issue_price']) }} của chính sổ thuế — nhiều khả năng file 152 ghi sai số lượng nhập">giá nghi ngờ</span>
                                @endif
                            </td>

                            <td class="n grp">{!! $book($r['ivt_value'], $num) !!}</td>
                            <td class="n">{!! $book($r['book_value'], $num) !!}</td>
                            <td class="n">{{ $r['val_diff'] === null ? '' : $num($r['val_diff']) }}</td>
                            <td class="n">{!! $pct($r['val_pct']) !!}</td>

                            <td class="n grp sub">{!! $book($r['book_val'], $num) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">TỔNG — {{ $num($rows->count()) }} mặt hàng</td>
                        <td class="grp sub" colspan="3">
                            {{ $num($totals['converted']) }}/{{ $num($rows->count()) }} mã quy đổi được sang ĐVT 152
                        </td>
                        <td class="n grp">{{ $qty($totals['qty']) }}</td>
                        <td class="sub" colspan="4">đơn vị hỗn hợp, không cộng được</td>
                        <td class="sub grp">đơn giá không cộng được</td>
                        <td class="n grp">{{ $num($totals['cost_compared']) }}</td>
                        <td class="n">{{ $num($totals['book_val']) }}</td>
                        <td class="n">{{ $num($totals['val_diff']) }}</td>
                        <td class="n">{!! $pct($totals['val_pct']) !!}</td>
                        <td class="n grp sub">{{ $num($totals['book_raw']) }}</td>
                    </tr>
                    <tr>
                        <td colspan="17" class="sub" style="font-weight:400">
                            Nhóm “Thành tiền” định giá <b>cả hai bên bằng đơn giá nhập của 152</b>, nên chênh lệch ở đây
                            <b>thuần là chênh số lượng</b> — giá đã triệt tiêu.
                            Chỉ cộng trên <b>{{ $num($totals['compared']) }}</b> mã có đủ cả hai bên lẫn đơn giá
                            (bỏ {{ $num($rows->count() - $totals['compared']) }} mã còn lại — cộng vào là tự tạo ra chênh lệch).
                            @if ($totals['borrowed'] > 0)
                                <b>{{ $num($totals['borrowed']) }}</b> mã tháng này không nhập nên mượn đơn giá của tháng gần nhất
                                (có nhãn <span class="tag">T…</span> ở cột Đơn giá).
                            @endif
                            @if ($totals['unpriced'] > 0)
                                <b>{{ $num($totals['unpriced']) }}</b> mã có ở sổ thuế nhưng <b>cả năm không nhập lần nào</b> nên không định giá được.
                            @endif
                            @if ($totals['odd'] > 0)
                                <b class="bad">{{ $num($totals['odd']) }} mã có giá nhập lệch quá 3 lần so với giá xuất của chính sổ thuế</b>
                                (nhãn <span class="tag bad-tag">giá nghi ngờ</span>, chiếm {{ $num($totals['odd_value']) }} trong tổng bên IVT) —
                                nhiều khả năng file 152 ghi sai số lượng nhập, nên đọc số của chúng có chừng mực.
                            @endif
                            Cột cuối là giá trị xuất kho <b>nguyên văn cột P</b> của file 152
                            (tổng {{ $num($totals['book_raw']) }}) — con số duy nhất trên trang tra ngược thẳng vào file được.
                        </td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    <details class="card" style="margin-bottom:24px">
        <summary><b>Công thức tính từng cột</b> — số ở bảng trên lấy từ đâu ra</summary>

        <p class="warnbox">
            <b>Màn hình này không nhân gì cả.</b> Mọi con số tiền đều là <code>SUM()</code> của một cột
            có sẵn trong DB, mà cột đó lưu <b>nguyên xi</b> giá trị IVT trả về ở API báo cáo
            <code>{{ $side === 'out' ? 'stock-out-summary' : 'stock-in-summary' }}</code>.
            Tiền là do IVT tính trên từng chứng từ rồi tự cộng lại, không phải do đây tính.
        </p>

        <div class="scroll">
        <table class="doc">
            <thead>
                <tr><th>Cột trên bảng</th><th>Công thức</th><th>Ghi chú</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><b>SL gốc</b></td>
                    <td><code>SUM(main_unit_qty)</code></td>
                    <td>Số lượng theo đơn vị chính của mặt hàng trong IVT.</td>
                </tr>
                <tr>
                    <td><b>ĐVT gốc</b></td>
                    <td><code>main_unit_id</code> + <code>main_unit_name</code></td>
                    <td>Mã đơn vị (GR, KG, TUI…) và tên hiển thị (Gram, Kg, Túi…).</td>
                </tr>
                <tr>
                    <td><b>Hệ số</b></td>
                    <td>1 ĐVT&nbsp;152 = <i>hệ số</i> × ĐVT gốc</td>
                    <td>
                        Ưu tiên hệ số khách hàng đã xác nhận (<code>DoiSoat152::ITEM_UNIT_FACTORS</code>),
                        sau đó tới đồ thị quy đổi riêng của từng mặt hàng lấy từ IVT
                        (<code>UnitConversionCatalog</code>, tìm đường bằng BFS nên quy đổi nhiều bước vẫn ra).
                        Không có đường quy đổi thì để trống — <b>không suy đoán</b>.
                    </td>
                </tr>
                <tr>
                    <td><b>SL quy đổi</b></td>
                    <td><code>SL gốc ÷ hệ số</code></td>
                    <td>Đưa số lượng IVT về đúng đơn vị sổ thuế đang dùng.</td>
                </tr>
                <tr>
                    <td><b>ĐVT 152</b></td>
                    <td>cột D của <code>{{ $taxWorkbook }}</code></td>
                    <td>Khớp theo mã hàng (cột B). Mã không có trong sổ thuế thì hiện “không có ở 152”.</td>
                </tr>
                <tr>
                    <td><b>Số lượng · 152</b></td>
                    <td>cột <b>O</b> của sổ thuế</td>
                    <td>“Xuất kho — Số lượng”. Lấy nguyên, không quy đổi (đã sẵn theo ĐVT 152).</td>
                </tr>
                <tr>
                    <td><b>Số lượng · 152 cả tồn</b></td>
                    <td><code>cột Q + cột O</code></td>
                    <td>SL cuối kỳ + SL xuất kho, cả hai lấy ở file 152 — tổng lượng sổ thuế ghi nhận trong kỳ.</td>
                </tr>
                <tr>
                    <td><b>Số lượng · Tỉ lệ</b></td>
                    <td><code>(SL IVT + tồn cuối kỳ ÷ số tháng còn lại) ÷ SL 152</code></td>
                    <td>
                        Tồn cuối tháng không mất đi — nó sẽ được dùng dần trong những tháng còn lại
                        của kỳ 6 tháng, nên được <b>chia đều</b> vào mẫu số.
                        Đang xem <b>tháng {{ $month }}</b> thì còn <b>{{ max(6 - $month, 0) }} tháng</b>
                        (6 − {{ $month }}), mỗi tháng gánh {{ max(6 - $month, 0) > 0 ? '1/'.(6 - $month) : '—' }} chỗ tồn.
                        Tử số vì thế là mức tiêu hao <b>đáng lẽ phải có</b> của tháng, chia cho mức sổ thuế đang ghi —
                        ra <b>1,68</b> nghĩa là phải đẩy số xuất lên 1,68 lần mới tiêu hết chỗ tồn.
                        Làm tròn 2 chữ số thập phân; mọi vế cùng ĐVT 152 nên tỉ lệ không thứ nguyên.
                        <br><b>Tháng 6 không còn tháng nào phía sau</b> nên phần tồn bị bỏ khỏi tử số —
                        chia cho 0 không ra con số đọc được.
                        Để trống khi mã không có ở sổ thuế, chưa quy đổi được đơn vị, hoặc sổ thuế xuất 0.
                    </td>
                </tr>
                <tr>
                    <td><b>Đơn giá</b></td>
                    <td><code>cột L ÷ cột K</code></td>
                    <td>
                        <b>Giá trị nhập kho ÷ Số lượng nhập kho</b>, lấy từ file 152 — giá mua thực tế.
                        Đây là <b>đơn giá duy nhất</b> trên toàn bộ bảng, dùng cho cả hai bên.
                        Tháng nào không nhập thì <b>lấy giá của tháng gần nhất có nhập</b> —
                        lùi về trước trước (hàng đang tồn mua từ lúc đó), hết mới tìm sang tháng sau
                        (tháng 1 chỉ còn cách này). Giá mượn có nhãn <span class="tag">T…</span> ghi rõ lấy từ tháng nào.
                        Chỉ mượn được khi tháng đó <b>cùng đơn vị</b> — giá một túi không phải giá một ký.
                    </td>
                </tr>
                <tr>
                    <td><b>Thành tiền · IVT</b></td>
                    <td><code>SL IVT × đơn giá nhập 152</code></td>
                    <td>
                        Số lượng của IVT nhưng <b>giá của 152</b>. Giá vốn IVT (<code>amount_cost</code>)
                        <b>không còn được dùng để định giá</b> — khách hàng chốt là bảng giá bên IVT
                        không đáng tin, chỉ số lượng mới tin được.
                    </td>
                </tr>
                <tr>
                    <td><b>Thành tiền · 152</b></td>
                    <td><code>SL 152 × đơn giá nhập 152</code></td>
                    <td>
                        Cùng một đơn giá với cột bên trái, nên hiệu hai cột <b>thuần là chênh số lượng</b>:
                        đã định giá cùng một giá thì phần chênh không thể do giá mà ra.
                    </td>
                </tr>
                <tr>
                    <td><b>152 gốc (cột P)</b></td>
                    <td>cột <b>P</b> của sổ thuế</td>
                    <td>
                        “Xuất kho — Giá trị” nguyên văn, không định giá lại — con số duy nhất trên bảng
                        tra ngược thẳng vào file 152 được. Cột M/N (“bán hàng”) bằng 0 toàn bộ nên không dùng.
                    </td>
                </tr>
                <tr>
                    <td><b>Δ và Δ %</b></td>
                    <td><code>IVT − 152</code> ; <code>(IVT − 152) ÷ |152| × 100</code></td>
                    <td>
                        Dương = IVT nhiều hơn sổ thuế. Tô màu chỉ để dễ đọc:
                        <span class="ok">&lt;1%</span> · <span class="warn">1–10%</span> · <span class="bad">&gt;10%</span> —
                        <b>không phải kết luận đúng/sai</b>.
                    </td>
                </tr>
                <tr>
                    <td><b>Thành tiền theo giá GD</b></td>
                    <td><code>SUM(amount)</code></td>
                    <td>
                        Giá trị theo <b>đơn giá giao dịch</b> (<code>main_unit_price</code>). Chỉ để tham chiếu,
                        <b>không</b> dùng đối soát 152.
                    </td>
                </tr>
            </tbody>
        </table>
        </div>

        <p><b>Phạm vi cộng (mệnh đề WHERE)</b> — bảng <code>{{ $table }}</code>:</p>
        <pre class="sql">SELECT item_id, main_unit_id,
       SUM(main_unit_qty), SUM(amount), SUM(amount_cost)
  FROM {{ $table }}
 WHERE {{ $typeColumn }} IN ({{ implode(', ', $selectedTypes) ?: '—' }})
   AND period_from &gt;= '{{ $startDate }}'
   AND period_to   &lt;= '{{ $endDate }}'{{ $selectedWarehouses === [] ? '' : "\n   AND ".$warehouseColumn." IN (".count($selectedWarehouses)." kho đã chọn)" }}
 GROUP BY item_id, main_unit_id</pre>

        <p><b>Vì sao “Thành tiền” không đúng bằng SL × đơn giá.</b>
            Một dòng báo cáo là tổng của nhiều chứng từ, mỗi chứng từ có đơn giá và làm tròn riêng, còn
            <code>main_unit_price</code> chỉ là đơn giá đại diện. Kiểm trên toàn bộ dữ liệu 2026:
            <code>SUM(main_unit_qty × main_unit_price)</code> lệch <b>0,19%</b> so với <code>SUM(amount)</code>
            (khoảng 12% số dòng lệch; riêng {{ $side === 'out' ? 136 : 216 }} dòng có tiền nhưng số lượng bằng 0).
            Nên lấy thẳng <code>amount</code> / <code>amount_cost</code>, <b>đừng tự nhân lại</b> —
            nhân lại là ra số khác với IVT.
        </p>

        <p><b>Bung công thức chế biến.</b>
            Khi bật, mỗi món do Bếp làm ra được thay bằng nguyên liệu thô của nó, đệ quy đến khi không còn
            công thức nào (COT_TS_KHOAIMON → NHAIXANH + SUADAC + BOTSUA; TP_KEMCOM → TP_SOTCOM → … ).
            Công thức của IVT viết cho <b>1 đơn vị thành phẩm</b>, nên:
            <code>SL nguyên liệu = SL thành phẩm ÷ hệ số(đvt kho → đvt công thức) × định lượng</code>.
            Tiền thì <b>chia theo tỷ trọng</b> giá trị các nguyên liệu trong công thức:
            <code>giá vốn NL = giá vốn thành phẩm × (amount của NL ÷ tổng amount công thức)</code> —
            lấy giá trong công thức làm <b>trọng số</b> chứ không lấy làm tiền, vì tiền phải là số đã hạch toán thật.
            Nhờ vậy <b>tổng tiền trước và sau khi bung bằng nhau đến từng đồng</b>, chỉ đổi chỗ.
            Nguyên liệu bung ra được gộp vào đúng dòng đang có sẵn (quy về đơn vị tồn kho của nó), và dòng đó
            hiện nhãn <span class="tag">bung CT +…</span> cho biết bao nhiêu tiền là do bung mà có.
            Mã nào không quy đổi được đơn vị hoặc công thức không có giá trị để chia thì <b>giữ nguyên, không bung</b>,
            và được liệt kê ở khung cảnh báo — không bịa hệ số.
        </p>

        <p><b>Khi sổ thuế và IVT dùng mã khác nhau.</b>
            Kế toán có thể tách một mặt hàng ra nhiều mã mà IVT chỉ có một.
            Trường hợp đang gặp là <b>TRADEN</b>: IVT ghi hết vào <code>TRADEN</code> (đơn vị TUI),
            còn sổ thuế dùng <code>TRADEN2</code> (Túi) đầu năm rồi chuyển sang <code>TRADEN</code> (kg) về sau.
            Không con số nào suy ra được mã nào mới là mã “sống” trong tháng, nên phải khai bằng tay ở
            <code>TaxUnitCatalog::ITEM_ALIASES</code> — theo <b>từng tháng</b>.
            Dòng nào đang đọc sang mã khác thì hiện nhãn <span class="tag">mã 152: …</span> ngay ở cột ĐVT 152,
            không thay ngầm.
        </p>

        <p><b>Mã “chờ quy đổi” — điền hệ số ở đâu.</b>
            Khi sổ thuế đếm một mã bằng đơn vị mà IVT không khai đường quy đổi nào tới
            (ví dụ sổ thuế ghi “Hộp”, IVT ghi “KG”), màn hình để trống thay vì đoán.
            Hệ số phải <b>hỏi khách hàng</b> — mỗi mặt hàng một quy cách đóng gói riêng, không suy từ mã khác được —
            rồi điền vào một chỗ duy nhất:
        </p>
        <pre class="sql">// app/Console/Commands/DoiSoat152.php
public const ITEM_UNIT_FACTORS = [
    'BOTTHACH'  =&gt; 1.0,                            // 1 Gói (thuế) = 1 TUI (IVT)
    'SUATUOITH' =&gt; ['qty' =&gt; 1000.0, 'unit' =&gt; 'ML'], // 1 Hộp (thuế) = 1000 ML
];</pre>
        <p>
            Hai cách viết. Số trần nghĩa là <b>1 đơn vị sổ thuế bằng bấy nhiêu đơn vị IVT của chính dòng đó</b>.
            Dạng <code>['qty' =&gt; …, 'unit' =&gt; …]</code> là <b>cách nên dùng</b>: nó ghi đúng câu khách hàng nói
            (“1 hộp = 1000 ml”) rồi để hệ thống tự quy sang đơn vị mà kho đang tồn — số trần sẽ sai âm thầm
            vào ngày IVT đổi mặt hàng đó từ LIT sang ML.
            Điền xong thì <b>cả màn hình này lẫn <code>app:doi-soat-152</code> đều nhận ngay</b> — cùng đọc một hằng số đó,
            không phải sửa hai nơi. Mã nào chưa điền sẽ nằm ở sheet “Chờ quy đổi” của file đối soát.
        </p>

        <p><b>Bung công thức KHÔNG phải lúc nào cũng đúng — coi chừng đếm trùng.</b>
            Cửa hàng tự đốt nguyên liệu để làm topping: khoản đó đã nằm ở <b>loại 6 (XSD)</b>, 1.265.784.701 đ
            tại 4 cửa hàng trong 6 tháng đầu 2026, gồm chính NHAIXANH, HOPPHOMAI, BOTSUA, TRADEN, SUADAC.
            Nếu vừa tích loại 6 vừa bung công thức thì lượng nguyên liệu ấy bị cộng hai lần.
            Bốn cách đọc, đo trên <b>tháng 6/2026, 4 cửa hàng</b> (tháng khác cùng quy luật):
        </p>

        <div class="scroll">
        <table class="doc">
            <thead><tr><th>Cấu hình</th><th class="n">IVT</th><th class="n">Sổ thuế</th><th class="n">Chênh</th><th>Đọc thế nào</th></tr></thead>
            <tbody>
                <tr><td>gi 1,2,4,5,6 — <b>không bung</b></td><td class="n">608.665.182</td><td class="n">522.088.423</td><td class="n">+16,6%</td>
                    <td>87 mã. Cách <code>app:doi-soat-152</code> đang dùng — món chế biến rơi ra ngoài vì sổ thuế không có mã đó.</td></tr>
                <tr><td>gi 1,2,4,5,6 — <b>có bung</b></td><td class="n">943.014.717</td><td class="n">607.301.346</td><td class="n bad">+55,3%</td>
                    <td><b>Sai — đếm trùng</b> với XSD.</td></tr>
                <tr><td>gi 1,2,4,5 — <b>có bung</b></td><td class="n">747.022.324</td><td class="n">607.301.346</td><td class="n">+23,0%</td>
                    <td>103 mã — nhiều nhất. Không trùng: bỏ XSD, lấy nguyên liệu qua thành phẩm bán ra.</td></tr>
                <tr><td>gi 1,2,4,5 — không bung</td><td class="n">412.672.789</td><td class="n">522.072.324</td><td class="n">−21,0%</td>
                    <td>Thiếu hẳn phần nguyên liệu nằm trong thành phẩm.</td></tr>
            </tbody>
        </table>
        </div>

        <p><b>Cơ sở của sổ thuế 152 — đây là điều kiện để các cột “152” có nghĩa.</b>
            Sổ thuế ghi tên kho là “Bếp Trung Liệt” nhưng thực tế là <b>tổng của 4 cửa hàng</b>
            (CHLANGHA, CHGIANGVANMINH, CHLETRONGTAN, CHKHUCTHUADU — khách hàng đã xác nhận),
            kỳ {{ $taxPeriod['from'] ?? '?' }} → {{ $taxPeriod['to'] ?? '?' }}, và không bao gồm điều chuyển nội bộ.
            Lọc khác đi thì hai bên không còn cùng một phép đo — màn hình sẽ hiện cảnh báo vàng ở trên.
            Kỳ thì không sai được nữa vì mỗi tháng đọc đúng file <code>Bang_152_thang_N.xlsx</code> của tháng đó;
            màn hình vẫn đối chiếu dòng “Tháng … năm …” bên trong file, lệch là báo — phòng trường hợp file bị đặt sai tên.
        </p>

        <p><b>File sổ thuế đọc trực tiếp, không cache.</b>
            Mỗi lần tải trang là đọc lại <code>storage/Bang_152_thang_N.xlsx</code> từ đĩa
            (khoảng 110 dòng, ~50 ms), nên vừa lưu file mới xong F5 là thấy ngay.
            Thời điểm file được lưu hiện ngay dưới ô chọn tháng để đối chiếu.
        </p>

        <p><b>Vì sao tổng chỉ cộng trên các mã có ở cả hai bên.</b>
            IVT có những mã sổ thuế không có (phần lớn là bán thành phẩm do Bếp làm ra) và ngược lại.
            Đem tổng-tất-cả-IVT trừ tổng-sổ-thuế là tạo ra một khoản chênh chỉ vì hai bên không cùng danh mục.
            Nên nhóm “Thành tiền” ở dòng TỔNG chỉ cộng phần giao nhau, và ghi rõ đã bỏ bao nhiêu mã.
        </p>

        <p><b>Tổng số lượng chỉ để tham khảo.</b> Nó cộng ngang các mặt hàng đếm bằng đơn vị khác nhau
            (gram + túi + cái), nên không phải một đại lượng đo được. Con số so sánh được giữa các bộ lọc
            là tiền. Cột “SL quy đổi” cũng vậy: chỉ {{ $totals['converted'] }}/{{ $rows->count() }} mã
            quy đổi được nên phần còn lại không nằm trong bất kỳ tổng nào.
        </p>

        <p><b>Loại 3 (điều chuyển) mặc định tắt.</b> Bếp chuyển hàng ra quán là một lần xuất, quán bán tiếp
            là lần thứ hai — cùng một lô hàng. Tích loại 3 vào là cộng trùng, chỉ nên bật khi muốn xem
            riêng khối lượng điều chuyển.
        </p>
    </details>

</div>
</body>
</html>
