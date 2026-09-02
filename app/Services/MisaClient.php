<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * MISA AMIS kế toán (actapp.misa.vn), the accountant's own books.
 *
 * Unlike Fabi and IVT there is no login endpoint to call: MISA's web app is
 * session based, so the browser's bearer token, cookie jar and context header
 * are copied out of a live session and kept in .env. They expire within a day,
 * which is why every failure says so plainly instead of surfacing as an empty
 * list — a stale token returns 401, not zero rows, and the two must never be
 * confused.
 *
 * The device id and X-MISA-Context are not decoration: the API rejects a
 * request without them, and the context carries which tenant, database and
 * branch the numbers belong to. Sending someone else's context would read a
 * different company's books.
 *
 * Editing a posted voucher takes four calls in order — unpost, read, save,
 * post. Stopping halfway leaves the voucher unposted, i.e. off the books, so
 * callers must treat a failure between them as something to finish by hand.
 */
class MisaClient
{
    private ?string $lastError = null;

    /**
     * So lan thu mot request truoc khi chiu thua.
     */
    private const MAX_ATTEMPTS = 4;

    private const RETRY_DELAY_MS = 700;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $cookie,
        private readonly string $deviceId,
        private readonly string $context,
    ) {
        $missing = array_keys(array_filter([
            'MISA_TOKEN' => $token === '',
            'MISA_COOKIE' => $cookie === '',
            'MISA_DEVICE_ID' => $deviceId === '',
            'MISA_CONTEXT' => $context === '',
        ]));

        if ($missing !== []) {
            throw new RuntimeException('Thiếu cấu hình MISA trong .env: '.implode(', ', $missing));
        }
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * One page of the "Xuất kho" voucher list.
     *
     * The filter/sort payload is passed through verbatim from the caller: MISA
     * addresses columns by numeric property ids that mean nothing on their own,
     * so rewriting them here would only hide what is being asked for.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function outwardList(array $payload): ?array
    {
        return $this->send('POST', '/g1/api/in/v1/in_inward_outward_list/paging_filter_v2', $payload);
    }

    /**
     * One page of the "Don dat hang" (sa_order) list.
     *
     * Same shape as outwardList: numeric property ids passed through verbatim.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function saOrders(array $payload): ?array
    {
        return $this->send('POST', '/g2/api/sa/v1/sa_order/paging_filter_v2', $payload);
    }

    /**
     * Raw passthrough for probing endpoints whose shape is not yet known.
     *
     * @param  array<mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public function raw(string $method, string $path, ?array $payload = null): ?array
    {
        return $this->send($method, $path, $payload);
    }

    /**
     * Header of one voucher.
     *
     * @return array<string, mixed>|null
     */
    public function outwardMaster(string $refid): ?array
    {
        $response = $this->send('GET', '/g1/api/in/v1/in_outward/'.$refid);

        return is_array($response['Data'] ?? null) ? $response['Data'] : null;
    }

    /**
     * Every line of one voucher, with every column.
     *
     * `columns` is deliberately omitted: ask for the twelve the grid shows and
     * MISA returns exactly those twelve, which is not enough to write the
     * voucher back. Leaving it out yields 55 columns — everything the save call
     * needs bar a handful of constants.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function outwardDetails(string $refid): ?array
    {
        $response = $this->send('POST', '/g1/api/in/v1/in_outward/get_paging_detail', [
            'sort' => '[{"property":4555,"desc":false,"data_type":4,"operand":1}]',
            'filter' => [
                ['property' => 3993, 'operator' => 7, 'operand' => 1, 'value' => $refid, 'data_type' => 10],
            ],
            'pageIndex' => 1,
            'pageSize' => 500,
            'useSp' => false,
            'view' => 56,
            'loadMode' => 2,
        ]);

        if ($response === null) {
            return null;
        }

        $rows = $response['Data']['PageData'] ?? null;

        return is_array($rows) ? $rows : null;
    }

    /**
     * Bỏ ghi sổ — a posted voucher cannot be edited, so this comes first.
     *
     * @param  array<string, mixed>  $auditLog
     * @return array<string, mixed>|null
     */
    public function unpost(string $refid, int $editVersion, string $branchId, array $auditLog): ?array
    {
        return $this->send('DELETE', '/g1/api/ledger/v1/ledger/unpost', [
            'refid' => $refid,
            'reftype' => 2023,
            'edit_version' => $editVersion,
            'branchID' => $branchId,
            'tableName' => 'in_outward',
            'PassWarnings' => ['WarningUnPortExistOutINOutwardAfter' => 'true'],
            // MISA hoi lai khi chung tu da duoc phan bo chi phi chung trong ky
            // tinh gia thanh. Tren web la hop thoai "Co/Khong"; o day dong y
            // truoc, khong thi bi chan giua chung.
            'IsPassAllWarning' => true,
            'auditing_log' => $auditLog,
            'is_check_exist_outward' => false,
        ]);
    }

    /**
     * Ghi sổ lại.
     *
     * `allowOverOutwardStock` is what the browser sends: raising a consumption
     * quantity can push the issue past what the ledger thinks is on hand, and
     * without this MISA refuses the posting.
     *
     * @param  array<string, mixed>  $auditLog
     * @return array<string, mixed>|null
     */
    public function post(string $refid, int $editVersion, string $branchId, array $auditLog): ?array
    {
        return $this->send('POST', '/g1/api/ledger/v1/ledger/post', [
            'refid' => $refid,
            'reftype' => 2023,
            'edit_version' => $editVersion,
            'branchID' => $branchId,
            'tableName' => 'in_outward',
            'allowOverOutwardStock' => true,
            'PassWarnings' => ['WarningPostExistOutINOutwardAfter' => 'true'],
            'IsPassAllWarning' => true,
            'IsPostAfterSave' => true,
            'auditing_log' => $auditLog,
            'is_check_exist_outward' => false,
        ]);
    }

    /**
     * Save the whole voucher back — master, every line, and the audit entry.
     *
     * @param  array<int, array<string, mixed>>  $payload  the one-element list MISA expects
     * @return array<string, mixed>|null
     */
    public function saveOutwardFull(array $payload): ?array
    {
        return $this->send('PUT', '/g1/api/in/v1/in_outward/full', $payload);
    }

    /**
     * Báo cáo "Tổng hợp tồn kho" — đầu kỳ, nhập, xuất, cuối kỳ theo từng mã.
     *
     * Đây là sổ của chính MISA, không phải file Excel kế toán gửi sang, nên nó
     * là chỗ duy nhất nói được tồn kho SAU khi lệnh sửa phiếu đã chạy. Dùng để
     * bắt mã bị xuất quá tay thành tồn âm.
     *
     * Phải gọi HAI lần, không phải một: lần đầu `p_is_refresh = true` bảo MISA
     * dựng số liệu vào một bảng tạm và luôn trả về 0 dòng; lần sau
     * `p_is_refresh = false` với CÙNG `p_session_key` mới đọc được bảng đó. Gọi
     * một lần rồi kết luận "không có dữ liệu" là hiểu sai giao thức.
     *
     * `parameters` là JSON đã mã hoá base64 — MISA nhận đúng dạng đó.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function inventoryBalance(string $fromDate, string $toDate, string $stockId): ?array
    {
        $sessionKey = bin2hex(random_bytes(32));
        $sessionId = (string) Str::uuid();

        $parameters = function (bool $refresh) use ($fromDate, $toDate, $stockId, $sessionKey): string {
            return base64_encode((string) json_encode([
                'p_branch_id' => $this->branchId().',',
                'p_include_dependent_branch' => false,
                // Giờ Việt Nam đổi sang UTC: 17:00 hôm trước = 00:00 hôm sau.
                'p_from_date' => gmdate('Y-m-d\TH:i:s.000\Z', (int) strtotime($fromDate.' 00:00:00 +07:00')),
                'p_to_date' => gmdate('Y-m-d\TH:i:s.000\Z', (int) strtotime($toDate.' 00:00:00 +07:00')),
                'p_unit_type' => 0,
                'p_iic_misa_code_id' => '',
                'p_list_stock_id' => $stockId.',',
                'p_stock_filter' => '',
                'p_is_management_book' => false,
                'p_is_refresh' => $refresh,
                'p_session_key' => $sessionKey,
            ], JSON_UNESCAPED_UNICODE));
        };

        $ask = fn (bool $refresh, int $page): ?array => $this->send(
            'POST',
            '/g1/api/report/v1/report/dynamic/v2/paging_filter',
            [
                'parameters' => $parameters($refresh),
                'report_id' => base64_encode('INInventoryBalanceSummary'),
                'requestFrom' => 1,
                'sessionId' => $sessionId,
                'actionLoadReport' => 2,
                'reportList' => [
                    'report_id' => 'INInventoryBalanceSummary',
                    'function_report_name' => 'func_rpt_in_get_inventory_balance_summary',
                    'rp_function_name_async' => 'func_rpt_in_get_inventory_balance_summary_v3',
                    'procedure_name' => 'Proc_INR_GetINInventoryBalanceSummary',
                    'table_name' => 'in_inventory_balance_summary',
                    'report_type' => 5,
                    'report_style' => 3,
                    'inv_method' => 2,
                    'summary_type' => 1,
                    'group_summary_type' => 1,
                    'is_system' => true,
                    'is_pure' => true,
                    'version' => 2,
                    'default_sort' => ';stock_name;stock_name,inventory_item_code;',
                    'apply_pending_data' => '[5]',
                ],
                'isViewMCP' => false,
                'pageIndex' => $page,
                // 100 là con số trình duyệt gửi. Xin 500 cho đỡ số lần gọi thì
                // MISA trả về rỗng mà vẫn Success = true — im lặng chứ không báo
                // lỗi, nên đừng nâng lên.
                'pageSize' => 100,
                'useSp' => false,
                'columns' => (string) json_encode([
                    ['field' => 'stock_name', 'dataformat' => 12],
                    ['field' => 'inventory_item_code', 'dataformat' => 12],
                    ['field' => 'inventory_item_name', 'dataformat' => 12],
                    ['field' => 'unit_name', 'dataformat' => 12],
                    ['field' => 'opening_quantity', 'dataformat' => 11],
                    ['field' => 'opening_amount', 'dataformat' => 2],
                    ['field' => 'total_in_quantity', 'dataformat' => 11],
                    ['field' => 'total_in_amount', 'dataformat' => 2],
                    ['field' => 'total_out_quantity', 'dataformat' => 11],
                    ['field' => 'total_out_amount', 'dataformat' => 2],
                    ['field' => 'closing_quantity', 'dataformat' => 11],
                    ['field' => 'closing_amount', 'dataformat' => 2],
                ]),
            ]
        );

        if ($ask(true, 1) === null) {
            return null;
        }

        // Lần đầu chỉ ra lệnh dựng bảng tạm và trả về ngay; đọc sát quá thì bảng
        // chưa có dòng nào và MISA trả rỗng mà vẫn Success = true. Chờ rồi hỏi
        // lại vài lượt cho tới khi có dữ liệu.
        $rows = [];

        for ($wait = 1; $wait <= 6; $wait++) {
            $probe = $ask(false, 1);

            if (is_array($probe['Data']['PageData'] ?? null) && $probe['Data']['PageData'] !== []) {
                break;
            }

            usleep(1_500_000);
        }

        for ($page = 1; $page <= 40; $page++) {
            $response = $ask(false, $page);

            if ($response === null) {
                return $rows === [] ? null : $rows;
            }

            $pageData = $response['Data']['PageData'] ?? [];

            if (! is_array($pageData) || $pageData === []) {
                break;
            }

            $rows = array_merge($rows, $pageData);

            if (count($pageData) < 100) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Branch id nằm trong X-MISA-Context, không khai riêng.
     */
    private function branchId(): string
    {
        $context = json_decode($this->context, true);

        return is_array($context) ? (string) ($context['BranchId'] ?? '') : '';
    }

    /**
     * @param  array<mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function send(string $method, string $path, ?array $payload = null): ?array
    {
        $this->lastError = null;
        $response = null;

        // MISA thinh thoang reset ket noi giua chung (cURL 56). Mot lan chop
        // chap khong dang de ca luot chay dung lai va bo mot phieu nam ngoai
        // so, nen thu lai vai lan truoc khi bao hong.
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $request = Http::withHeaders($this->headers())->timeout(180);
                $response = $payload === null
                    ? $request->send($method, $this->baseUrl.$path)
                    : $request->send($method, $this->baseUrl.$path, ['json' => $payload]);

                break;
            } catch (Throwable $e) {
                $this->lastError = $e->getMessage();

                if ($attempt === self::MAX_ATTEMPTS) {
                    return null;
                }

                usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
            }
        }

        if ($response === null) {
            return null;
        }

        if (in_array($response->status(), [401, 403], true)) {
            $this->lastError = 'MISA từ chối ('.$response->status().') — token hoặc cookie đã hết hạn, '
                .'lấy lại từ trình duyệt rồi cập nhật .env.';

            return null;
        }

        if (! $response->successful()) {
            $this->lastError = 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 400);

            return null;
        }

        $data = $response->json();

        if (! is_array($data)) {
            $this->lastError = 'Phản hồi không phải JSON: '.mb_substr($response->body(), 0, 300);

            return null;
        }

        // MISA answers 200 with Success=false and the real reason in the body,
        // so the HTTP code alone says nothing about whether the write landed.
        if (($data['Success'] ?? true) === false) {
            // Lý do thật nằm ở Data.Message; ErrorsMessage thường rỗng nên báo theo
            // nó là mất hết thông tin ("MISA báo lỗi: []").
            $this->lastError = 'MISA báo lỗi: '.(
                $data['Data']['Message']
                ?? $data['SystemMessage']
                ?? json_encode($data['ErrorsMessage'] ?? $data, JSON_UNESCAPED_UNICODE)
            );

            return null;
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'en-US,en;q=0.9,vi;q=0.8',
            'Authorization' => 'Bearer '.$this->token,
            'Content-Type' => 'application/json',
            'Cookie' => $this->cookie,
            'Origin' => $this->baseUrl,
            'Referer' => $this->baseUrl.'/app/IN/INOutwardList',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                .'(KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
            'X-Device' => $this->deviceId,
            'X-MISA-Context' => $this->context,
        ];
    }
}
