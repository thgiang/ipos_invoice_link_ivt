<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client for the Fabi (POS/CMS) side of iPOS.
 *
 * Holds the bearer token obtained at login so callers can issue several
 * requests without re-authenticating.
 */
class FabiClient
{
    /**
     * Headers identifying the caller as the Fabi web CMS.
     *
     * @var array<string, string>
     */
    private const CLIENT_HEADERS = [
        'Accept' => 'application/json, text/plain, */*',
        'Origin' => 'https://fabi.ipos.vn',
        'Referer' => 'https://fabi.ipos.vn/',
        'accept-language' => 'vi',
        'fabi_type' => 'pos-cms',
        'x-client-timezone' => '25200000',
    ];

    private ?string $token = null;

    private ?string $lastError = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $email,
        private readonly string $password,
        private readonly string $accessToken,
    ) {}

    /**
     * Authenticate and remember the bearer token. Returns the full login
     * payload, or null when the login is rejected.
     *
     * @return array{token: string, company: array, brands: array, stores: array}|null
     */
    public function login(): ?array
    {
        $this->assertConfigured();

        $response = $this->baseRequest()
            ->withHeaders(['Content-Type' => 'application/json;charset=UTF-8'])
            ->post("{$this->baseUrl}/api/accounts/v1/user/login", [
                'email' => $this->email,
                'password' => $this->password,
            ]);

        if (! $response->successful()) {
            $this->lastError = $response->body();

            return null;
        }

        $data = $response->json('data');
        $this->token = $data['token'] ?? null;

        return $data;
    }

    /**
     * Fetch one page of the sale-by-date report for a store.
     *
     * @return array<string, mixed>|null
     */
    public function saleByDate(
        string $companyUid,
        string $brandUid,
        string $storeUid,
        int $startDate,
        int $endDate,
        int $page,
        int $resultsPerPage = 0,
    ): ?array {
        return $this->get('/api/reports_v1/v3/pos-cms/report/sale-by-date', array_filter([
            'company_uid' => $companyUid,
            'brand_uid' => $brandUid,
            'store_uid' => $storeUid,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'page' => $page,
            'results_per_page' => $resultsPerPage,
            'sort' => 'dsc',
            'store_open_at' => 0,
        ]));
    }

    /**
     * Orders with their full product lines (and each product's toppings).
     *
     * Must be the LIST endpoint: the singular `get-sale-by-tran-id` answers 404
     * for anything older than roughly two months (checked 25/08 and 10/08 fine,
     * 15/06 and back all 404), while this one still returns January orders.
     * Batching is a bonus — one call per order would be 88.000 calls.
     *
     * @param  array<int, string>  $tranIds
     *
     * @return array<string, mixed>|null
     */
    public function saleByListTranId(
        string $companyUid,
        string $brandUid,
        string $storeUid,
        array $tranIds,
    ): ?array {
        return $this->get('/api/v3/pos-cms/get-sale-by-list-tran-id', [
            'company_uid' => $companyUid,
            'brand_uid' => $brandUid,
            'store_uid' => $storeUid,
            'list_tran_id' => implode(',', $tranIds),
            'page' => 1,
            'results_per_page' => count($tranIds),
        ]);
    }

    /**
     * Fetch one page of VAT invoices for a store.
     *
     * @return array<string, mixed>|null
     */
    public function vatInvoices(
        string $companyUid,
        string $brandUid,
        string $storeUid,
        int $startDate,
        int $endDate,
        int $page,
        int $resultsPerPage = 50,
    ): ?array {
        return $this->get('/api/v3/pos-cms/vat-invoice', [
            'company_uid' => $companyUid,
            'brand_uid' => $brandUid,
            'store_uid' => $storeUid,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'page' => $page,
            'results_per_page' => $resultsPerPage,
        ]);
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
    private function get(string $path, array $query): ?array
    {
        $this->assertConfigured();

        $response = $this->baseRequest()
            ->withHeaders(['Authorization' => $this->token])
            ->get($this->baseUrl.$path, $query);

        if (! $response->successful()) {
            $this->lastError = "HTTP {$response->status()} — {$response->body()}";

            return null;
        }

        return $response->json();
    }

    private function baseRequest(): PendingRequest
    {
        return Http::withHeaders(self::CLIENT_HEADERS + [
            'access_token' => $this->accessToken,
        ]);
    }

    /**
     * @throws RuntimeException when a required credential is missing from .env
     */
    private function assertConfigured(): void
    {
        $missing = array_keys(array_filter([
            'FABI_EMAIL' => $this->email === '',
            'FABI_PASSWORD' => $this->password === '',
            'FABI_ACCESS_TOKEN' => $this->accessToken === '',
        ]));

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing Fabi credentials in .env: '.implode(', ', $missing)
            );
        }
    }
}
