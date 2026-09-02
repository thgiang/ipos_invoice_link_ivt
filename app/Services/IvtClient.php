<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client for the IVT (inventory) side of iPOS.
 *
 * Holds the user token obtained at login so callers can issue several requests
 * without re-authenticating, and centralises the browser fingerprint headers
 * the IVT API expects.
 */
class IvtClient
{
    /**
     * Headers describing the "browser" IVT believes it is talking to. These are
     * part of the API contract rather than configuration, so they stay here.
     *
     * @var array<string, string>
     */
    private const DEVICE_HEADERS = [
        'language' => 'vi',
        'device-type' => 'WEB',
        'x-timezone' => '7',
        'device-app-version' => '2.14.3',
        'device-os-platform' => 'Microsoft Windows',
        'device-os-browser' => 'Chrome',
        'device-os-version' => '145.0.0.0',
        'device-os-name' => 'Windows 10.0',
        'Origin' => 'https://ivt.ipos.vn',
        'Referer' => 'https://ivt.ipos.vn/',
    ];

    private ?string $userToken = null;

    private ?string $lastError = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $email,
        private readonly string $password,
        private readonly string $accessToken,
        private readonly string $deviceId,
        private readonly string $secretKey,
    ) {}

    /**
     * Authenticate and remember the user token. Returns null when the login is
     * rejected; the response body is then available via lastError().
     */
    public function login(): ?string
    {
        $this->assertConfigured();

        $response = $this->baseRequest()
            ->asJson()
            ->post("{$this->baseUrl}/api/main/v1/auth/login", [
                'user_email' => $this->email,
                'password' => $this->password,
            ]);

        if (! $response->successful()) {
            $this->lastError = $response->body();

            return null;
        }

        return $this->userToken = $response->json('data.user_token');
    }

    /**
     * Fetch one page of stock-out records for a given gi_type.
     *
     * @return array<string, mixed>|null
     */
    public function stockOuts(
        int $fromDate,
        int $toDate,
        int $giType,
        int $page,
        int $pageSize = 100,
    ): ?array {
        return $this->get('/api/main/v3/service/stock-out', [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'gi_type' => $giType,
            'search' => '',
            'financial_paper_id' => '',
            'from_warehouse_uid' => '',
            'customer_uid' => '',
            'gi_status' => 'COMPLETED',
            'page_size' => $pageSize,
            'page' => $page,
        ]);
    }

    /**
     * Fetch the detail payload (list_item, ...) of a single stock-out.
     *
     * @return array<string, mixed>|null
     */
    public function stockOutDetail(string $ivtId): ?array
    {
        return $this->get('/api/main/v3/service/stock-out', ['id' => $ivtId], 'data');
    }

    /**
     * Fetch one page of active recipes.
     *
     * @return array<string, mixed>|null
     */
    public function recipes(int $page, int $pageSize = 50): ?array
    {
        return $this->get('/api/main/v2/catalog/recipe', [
            'active' => 1,
            'page_size' => $pageSize,
            'page' => $page,
        ]);
    }

    /**
     * Fetch one page of the unit-conversion catalog.
     *
     * A conversion is always scoped to specific items — "1 túi = 1 kg" is only
     * ever true of a particular product — so entries must be applied per item,
     * never as a global unit rule.
     *
     * @return array<string, mixed>|null
     */
    public function unitConversions(int $page, int $pageSize = 50): ?array
    {
        return $this->get('/api/main/v2/catalog/unit-conversion', [
            'active' => 1,
            'page_size' => $pageSize,
            'page' => $page,
        ]);
    }

    /**
     * Fetch one page of the "Tổng hợp xuất" (stock-out summary) report.
     *
     * Quantities are aggregated over the whole [$fromDate, $toDate] window, so
     * the rows carry no date of their own. The report is heavy: leaving
     * $fromWarehouseUids empty makes it hang, and a window wider than roughly a
     * quarter trips the server's own statement timeout — crawl month by month.
     *
     * $giType filters to one stock-out category (1 XBH, 2 XDC, 3 XNB, 4 XH,
     * 5 XK, 6 XSD); pass null for every category at once.
     *
     * @param  string[]  $fromWarehouseUids
     * @return array<string, mixed>|null
     */
    public function stockOutSummary(
        int $fromDate,
        int $toDate,
        array $fromWarehouseUids,
        int $page,
        ?int $giType = null,
        int $pageSize = 500,
    ): ?array {
        return $this->get('/api/report/v1/inventory/stock-out-summary', [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'product_uid' => '',
            'item_uid' => '',
            'item_class_uid' => '',
            'from_warehouse_uid' => implode(',', $fromWarehouseUids),
            'to_warehouse_uid' => '',
            'gi_types' => $giType === null ? '' : (string) $giType,
            'customer_uid' => '',
            'supplier_uid' => '',
            'lot_no' => '',
            'page_size' => $pageSize,
            'page' => $page,
        ], timeout: 180);
    }

    /**
     * Fetch one page of the "Tổng hợp nhập" (stock-in summary) report.
     *
     * Mirrors stockOutSummary but filters on the receiving warehouse and on
     * gr_type instead. Needed because adjustments and write-offs happen in both
     * directions: stock found again has to be netted off stock written down.
     *
     * @param  string[]  $toWarehouseUids
     * @return array<string, mixed>|null
     */
    public function stockInSummary(
        int $fromDate,
        int $toDate,
        array $toWarehouseUids,
        int $page,
        ?int $grType = null,
        int $pageSize = 500,
    ): ?array {
        return $this->get('/api/report/v1/inventory/stock-in-summary', [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'product_uid' => '',
            'item_uid' => '',
            'item_class_uid' => '',
            'from_warehouse_uid' => '',
            'to_warehouse_uid' => implode(',', $toWarehouseUids),
            'gr_types' => $grType === null ? '' : (string) $grType,
            'customer_uid' => '',
            'supplier_uid' => '',
            'lot_no' => '',
            'page_size' => $pageSize,
            'page' => $page,
        ], timeout: 180);
    }

    /**
     * Body of the most recent failed response, for the caller to log.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function get(string $path, array $query, ?string $key = null, ?int $timeout = null): ?array
    {
        $this->assertConfigured();

        $response = $this->baseRequest()
            ->when($timeout, fn ($request) => $request->timeout($timeout))
            ->withHeaders(['user-token' => $this->userToken])
            ->get($this->baseUrl.$path, $query);

        if (! $response->successful()) {
            $this->lastError = "HTTP {$response->status()} — {$response->body()}";

            return null;
        }

        return $key ? $response->json($key) : $response->json();
    }

    private function baseRequest(): PendingRequest
    {
        return Http::withHeaders(self::DEVICE_HEADERS + [
            'Accept' => 'application/json, text/plain, */*',
            'access-token' => $this->accessToken,
            'device-id' => $this->deviceId,
            'secret-key' => $this->secretKey,
        ]);
    }

    /**
     * @throws RuntimeException when a required credential is missing from .env
     */
    private function assertConfigured(): void
    {
        $missing = array_keys(array_filter([
            'IVT_EMAIL' => $this->email === '',
            'IVT_PASSWORD' => $this->password === '',
            'IVT_ACCESS_TOKEN' => $this->accessToken === '',
            'IVT_DEVICE_ID' => $this->deviceId === '',
            'IVT_SECRET_KEY' => $this->secretKey === '',
        ]));

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing IVT credentials in .env: '.implode(', ', $missing)
            );
        }
    }
}
