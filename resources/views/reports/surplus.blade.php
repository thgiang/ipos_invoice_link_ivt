@php
    $num = fn ($v, $d = 0) => number_format((float) $v, $d, ',', '.');
    $qty = function ($v) {
        $v = (float) $v;

        return number_format($v, abs($v - round($v)) < 0.0005 ? 0 : 3, ',', '.');
    };
@endphp
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nhập nhiều — dùng ít</title>
<style>
    :root {
        --bg: #f5f6f8; --card: #fff; --line: #e2e5ea; --text: #1c2024;
        --muted: #6b7280; --accent: #1d4ed8; --warn: #92400e; --warn-bg: #fef3c7;
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text);
           font: 14px/1.45 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
    a { color: var(--accent); }
    .wrap { margin: 0; padding: 18px 22px; }
    nav { display: flex; gap: 8px; margin-bottom: 16px; }
    nav a { padding: 9px 18px; border-radius: 8px; background: var(--card);
            border: 1px solid var(--line); text-decoration: none; font-weight: 600; }
    nav a.on { background: var(--accent); border-color: var(--accent); color: #fff; }
    .card { background: var(--card); border: 1px solid var(--line);
            border-radius: 10px; padding: 16px; margin-bottom: 16px; }
    h1 { font-size: 19px; margin: 0 0 4px; }
    .lede { color: var(--muted); font-size: 13px; margin: 0 0 14px; }
    .filters { display: flex; flex-wrap: wrap; gap: 22px; align-items: flex-end; }
    fieldset { border: 0; padding: 0; margin: 0; }
    legend { font-size: 12px; font-weight: 700; text-transform: uppercase;
             letter-spacing: .04em; color: var(--muted); margin-bottom: 7px; display: block; }
    select { padding: 7px 9px; border: 1px solid var(--line); border-radius: 6px;
             font: inherit; background: #fff; }
    button { padding: 9px 22px; border: 0; border-radius: 6px; background: var(--accent);
             color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
    .note { background: var(--warn-bg); color: var(--warn); border-radius: 6px;
            padding: 9px 12px; margin-top: 12px; font-size: 13px; }
    .meta { color: var(--muted); font-size: 12.5px; margin-top: 10px; }
    .stats { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
    .stat { flex: 1 1 200px; background: var(--card); border: 1px solid var(--line);
            border-radius: 10px; padding: 12px 16px; }
    .stat span { display: block; font-size: 12px; color: var(--muted); }
    .stat b { font-size: 21px; font-variant-numeric: tabular-nums; }
    .scroll { overflow: auto; max-height: calc(100vh - 46px); }
    table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
    th, td { padding: 7px 10px; border-bottom: 1px solid var(--line); text-align: left; }
    thead th { position: sticky; background: #eef1f5; font-size: 11.5px; z-index: 2;
               text-transform: uppercase; letter-spacing: .03em; color: #41474f; }
    thead tr:first-child th { top: 0; height: 30px; }
    thead tr:nth-child(2) th { top: 30px; }
    th.c { text-align: center; background: #e3e8ef; }
    th.n, td.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    tbody tr:hover { background: #f8fafc; }
    tfoot td { font-weight: 700; background: #eef1f5; border-top: 2px solid #cbd2da; }
    .sub { color: var(--muted); font-size: 11.5px; }
    .grp { border-left: 2px solid #cbd2da; }
    .tag { display: inline-block; background: #fee2e2; color: #991b1b; border-radius: 4px;
           padding: 1px 6px; font-size: 11px; white-space: nowrap; }
    .bad { color: #b91c1c; font-weight: 600; }
    .warnc { color: #b45309; }
    .ok { color: #15803d; }
    code { background: #eef1f5; border-radius: 4px; padding: 1px 5px; font-size: 12.5px; }
    details summary { cursor: pointer; font-size: 15px; }
    details p { font-size: 13px; line-height: 1.6; color: #374151; max-width: 95ch; }
</style>
</head>
<body>
<div class="wrap">

    <nav>
        <a href="{{ route('reports.stock-out') }}">Xuất kho</a>
        <a href="{{ route('reports.stock-in') }}">Nhập kho</a>
        <a href="{{ route('reports.surplus') }}" class="on">Nhập nhiều — dùng ít</a>
    </nav>

    <div class="card">
        <h1>Nhập nhiều — dùng ít</h1>
        <p class="lede">
            Cộng dồn các file <code>Bang_152_thang_N.xlsx</code>: mặt hàng nào mua về nhiều mà xuất ra ít,
            tức là đang nằm tồn. Màn hình này <b>chỉ dùng sổ thuế</b>, không đụng tới số liệu IVT.
        </p>

        <form method="get" class="filters">
            <fieldset>
                <legend>Từ tháng</legend>
                <select name="from">
                    @foreach ($months as $m)
                        <option value="{{ $m['month'] }}" @selected($m['month'] === $from)>
                            {{ $m['label'] }}{{ $m['has_book'] ? '' : ' — chưa có file' }}
                        </option>
                    @endforeach
                </select>
            </fieldset>

            <fieldset>
                <legend>Đến tháng</legend>
                <select name="to">
                    @foreach ($months as $m)
                        <option value="{{ $m['month'] }}" @selected($m['month'] === $to)>
                            {{ $m['label'] }}{{ $m['has_book'] ? '' : ' — chưa có file' }}
                        </option>
                    @endforeach
                </select>
            </fieldset>

            <fieldset>
                <legend>Sắp xếp giảm dần theo</legend>
                <select name="sort">
                    <option value="qty" @selected($sort === 'qty')>Số lượng dư</option>
                    <option value="value" @selected($sort === 'value')>Giá trị dư</option>
                </select>
            </fieldset>

            <fieldset><button type="submit">Xem</button></fieldset>
        </form>

        <div class="meta">
            Đã cộng {{ count($covered) }} file:
            {{ collect($covered)->map(fn ($m) => 'T'.$m)->implode(', ') }}
            — đọc mới mỗi lần tải trang.
        </div>

        @if ($sort === 'qty')
            <div class="note">
                Đang xếp theo <b>số lượng</b> dư. Số lượng của các mặt hàng đo bằng đơn vị khác nhau
                (gam, cái, túi, kg) nên <b>không so ngang được</b> — đầu bảng sẽ luôn là hàng tính theo gam.
                Muốn biết mặt hàng nào <b>đọng nhiều tiền nhất</b> thì chuyển sang xếp theo
                <a href="{{ request()->fullUrlWithQuery(['sort' => 'value']) }}"><b>Giá trị dư</b></a>.
            </div>
        @endif

        @if ($totals['unbalanced'] > 0)
            <div class="note">
                <b>{{ $num($totals['unbalanced']) }} mã không thoả cân đối</b> ĐK + Nhập − Xuất = CK.
                Dòng đó có nhãn <span class="tag">lệch cân đối</span> — số dư của chúng không đọc được,
                phải kiểm lại file trước.
            </div>
        @endif

        @if ($totals['mixed'] > 0)
            <div class="note">
                <b>{{ $num($totals['mixed']) }} mã đổi đơn vị giữa các tháng</b> nên không cộng số lượng được
                (cộng kg với túi là vô nghĩa). Cột số lượng của chúng để trống, chỉ còn phần tiền.
            </div>
        @endif
    </div>

    <div class="stats">
        <div class="stat"><span>Số mặt hàng</span><b>{{ $num($totals['items']) }}</b></div>
        <div class="stat"><span>Tổng giá trị nhập</span><b>{{ $num($totals['in_v']) }}</b></div>
        <div class="stat"><span>Tổng giá trị xuất</span><b>{{ $num($totals['out_v']) }}</b></div>
        <div class="stat"><span>Chênh nhập − xuất</span>
            <b class="{{ $totals['surplus_v'] > 0 ? 'warnc' : 'ok' }}">{{ $num($totals['surplus_v']) }}</b>
            <span>tồn cuối kỳ theo sổ: {{ $num($totals['end_v']) }}</span></div>
    </div>

    <div class="card scroll" style="padding:0">
        @if ($rows->isEmpty())
            <div style="padding:40px;text-align:center;color:var(--muted)">Không có file 152 nào trong khoảng đã chọn.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th rowspan="2">#</th>
                        <th rowspan="2">Mã hàng</th>
                        <th rowspan="2">Tên hàng</th>
                        <th rowspan="2">ĐVT</th>
                        <th colspan="5" class="grp c">Số lượng</th>
                        <th colspan="3" class="grp c">Giá trị</th>
                        <th rowspan="2" class="n grp">Tồn cuối kỳ<br><span class="sub">giá trị</span></th>
                    </tr>
                    <tr>
                        <th class="n grp">Đầu kỳ</th>
                        <th class="n">Nhập</th>
                        <th class="n">Xuất</th>
                        <th class="n">Dư</th>
                        <th class="n">Đã dùng</th>
                        <th class="n grp">Nhập</th>
                        <th class="n">Xuất</th>
                        <th class="n">Dư</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $r)
                        <tr>
                            <td class="sub">{{ $i + 1 }}</td>
                            <td><b>{{ $r['code'] }}</b></td>
                            <td>{{ $r['name'] }}
                                @unless ($r['balanced'])
                                    <span class="tag" title="ĐK + Nhập − Xuất không bằng CK trong file">lệch cân đối</span>
                                @endunless
                                @if ($r['months'] < count($covered))
                                    <span class="sub">chỉ có ở {{ $r['months'] }}/{{ count($covered) }} tháng</span>
                                @endif
                            </td>
                            <td>{{ $r['mixed_units'] ? implode(' / ', $r['units']) : $r['unit'] }}</td>

                            <td class="n grp">{{ $r['mixed_units'] ? '' : $qty($r['open_q']) }}</td>
                            <td class="n">{{ $r['mixed_units'] ? '' : $qty($r['in_q']) }}</td>
                            <td class="n">{{ $r['mixed_units'] ? '' : $qty($r['out_q']) }}</td>
                            <td class="n {{ $r['surplus_q'] > 0 ? 'warnc' : '' }}">
                                {{ $r['mixed_units'] ? '—' : $qty($r['surplus_q']) }}
                            </td>
                            <td class="n">
                                @if ($r['used_pct'] === null || $r['mixed_units'])
                                    <span class="sub">—</span>
                                @else
                                    <span class="{{ $r['used_pct'] < 50 ? 'bad' : ($r['used_pct'] < 80 ? 'warnc' : 'ok') }}">{{ $num($r['used_pct']) }}%</span>
                                @endif
                            </td>

                            <td class="n grp">{{ $num($r['in_v']) }}</td>
                            <td class="n">{{ $num($r['out_v']) }}</td>
                            <td class="n {{ $r['surplus_v'] > 0 ? 'warnc' : '' }}">{{ $num($r['surplus_v']) }}</td>
                            <td class="n grp sub">{{ $num($r['end_v']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">TỔNG — {{ $num($totals['items']) }} mặt hàng</td>
                        <td class="grp sub" colspan="5">số lượng đơn vị hỗn hợp, không cộng được</td>
                        <td class="n grp">{{ $num($totals['in_v']) }}</td>
                        <td class="n">{{ $num($totals['out_v']) }}</td>
                        <td class="n">{{ $num($totals['surplus_v']) }}</td>
                        <td class="n grp sub">{{ $num($totals['end_v']) }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    <details class="card" style="margin-bottom:24px">
        <summary><b>Công thức và cách đọc</b></summary>

        <p><b>Nguồn.</b> Chỉ đọc các file <code>storage/Bang_152_thang_N.xlsx</code> trong khoảng tháng đã chọn,
            đọc trực tiếp mỗi lần tải trang nên sửa file xong F5 là thấy. Không dùng số liệu IVT ở bất kỳ cột nào.
        </p>

        <p><b>Công thức.</b>
            <code>Nhập = Σ cột K/L</code> · <code>Xuất = Σ cột O/P</code> ·
            <code>Dư = Nhập − Xuất</code> · <code>Đã dùng = Xuất ÷ Nhập</code>.
            Đầu kỳ lấy của tháng đầu tiên trong khoảng, Tồn cuối kỳ lấy của tháng cuối — cả hai là số dư,
            không phải tổng.
        </p>

        <p><b>Tự kiểm.</b> Với mỗi mã phải có <code>Đầu kỳ + Nhập − Xuất = Cuối kỳ</code>.
            Mã nào không thoả thì bị gắn nhãn <span class="tag">lệch cân đối</span>: khi đó con số “Dư”
            không phản ánh tồn thật mà phản ánh một chỗ sai trong file.
        </p>

        <p><b>“Đã dùng” là cột đáng nhìn nhất.</b> Số lượng dư lớn có thể chỉ vì mặt hàng đó vốn mua theo lô lớn.
            Tỉ lệ <code>Xuất ÷ Nhập</code> mới cho biết mua về rồi có dùng hay không:
            <span class="ok">≥80%</span> là quay vòng tốt, <span class="warnc">50–80%</span> là chậm,
            <span class="bad">&lt;50%</span> là mua nhiều hơn hẳn nhu cầu.
        </p>

        <p><b>Đừng đọc tổng cột số lượng.</b> Mỗi mặt hàng một đơn vị, cộng ngang gam với cái là vô nghĩa —
            nên dòng TỔNG chỉ cộng tiền. Vì cùng lý do, xếp theo số lượng chỉ để tra cứu,
            xếp theo <b>giá trị dư</b> mới trả lời được câu “tiền đang đọng ở đâu”.
        </p>
    </details>

</div>
</body>
</html>
