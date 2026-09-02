<?php

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * The IVT unit-conversion catalog, indexed for lookup.
 *
 * Every conversion in IVT is scoped to specific items: "1 túi = 1 kg" is only
 * ever true of one product, never of the unit "túi" in general. So the graph is
 * built per item_id, and a factor is only returned when a path exists inside
 * that item's own conversions.
 *
 * The one exception is the SI mass/volume prefixes (1 KG = 1000 GR,
 * 1 LIT = 1000 ML), which hold everywhere and are added to every item's graph.
 */
class UnitConversionCatalog
{
    private const CACHE_TTL_SECONDS = 86400;

    /**
     * Conversions that are true by definition, independent of the item.
     *
     * @var array<int, array{0: string, 1: string, 2: float}> [from, to, rate]
     */
    private const METRIC_EDGES = [
        ['KG', 'GR', 1000.0],
        ['LIT', 'ML', 1000.0],
    ];

    /**
     * item_id => [from_unit => [to_unit => rate]]
     *
     * @var array<string, array<string, array<string, float>>>
     */
    private array $graphs = [];

    private bool $loaded = false;

    /**
     * Set when the refresh failed and the on-disk cache was used past its TTL.
     */
    private ?string $staleWarning = null;

    public function __construct(private readonly IvtClient $ivt) {}

    /**
     * Why the catalog may be out of date, or null when it is fresh. Callers
     * should surface this: the factors are still usable, they are just older
     * than they should be.
     */
    public function staleWarning(): ?string
    {
        return $this->staleWarning;
    }

    /**
     * How many $to units make up one $from unit for this item, or null when the
     * item's catalog contains no path between them.
     */
    public function factor(string $itemId, string $from, string $to): ?float
    {
        $this->load();

        $from = self::normaliseUnit($from);
        $to = self::normaliseUnit($to);

        if ($from === $to) {
            return 1.0;
        }

        $graph = $this->graphFor($itemId);

        if ($graph === []) {
            return null;
        }

        // Breadth-first so the shortest chain of conversions wins.
        $queue = [[$from, 1.0]];
        $seen = [$from => true];

        while ($queue !== []) {
            [$unit, $rate] = array_shift($queue);

            foreach ($graph[$unit] ?? [] as $next => $step) {
                if (isset($seen[$next])) {
                    continue;
                }

                $accumulated = $rate * $step;

                if ($next === $to) {
                    return $accumulated;
                }

                $seen[$next] = true;
                $queue[] = [$next, $accumulated];
            }
        }

        return null;
    }

    /**
     * Every unit reachable for an item, for diagnostics.
     *
     * @return string[]
     */
    public function unitsFor(string $itemId): array
    {
        $this->load();

        return array_keys($this->graphFor($itemId));
    }

    /**
     * The item's own edges plus the universal metric ones.
     *
     * @return array<string, array<string, float>>
     */
    private function graphFor(string $itemId): array
    {
        $graph = $this->graphs[$itemId] ?? [];

        // The metric edges apply even to items the catalog says nothing about,
        // so they are added unconditionally rather than only to known items.
        foreach (self::METRIC_EDGES as [$from, $to, $rate]) {
            $graph[$from][$to] = $rate;
            $graph[$to][$from] = 1 / $rate;
        }

        return $graph;
    }

    /**
     * Load from the 24h JSON cache, fetching the whole catalog when it is stale.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->index($this->records());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        $cachePath = storage_path('app/ivt-cache/unit_conversions.json');
        $cached = is_file($cachePath);

        if ($cached && (time() - filemtime($cachePath)) < self::CACHE_TTL_SECONDS) {
            return json_decode((string) file_get_contents($cachePath), true) ?: [];
        }

        // A stale cache beats no catalog at all: packaging sizes change rarely,
        // whereas an unreachable IVT would otherwise take down every caller —
        // including a reconciliation that was minutes from finishing. The fall
        // back is recorded, never silent.
        try {
            $records = $this->fetch();
        } catch (Throwable $e) {
            if (! $cached) {
                throw $e;
            }

            $age = (int) round((time() - filemtime($cachePath)) / 3600);
            $this->staleWarning = "Không làm mới được bảng quy đổi từ IVT ({$e->getMessage()}); "
                ."đang dùng bản cache lưu cách đây {$age} giờ.";

            return json_decode((string) file_get_contents($cachePath), true) ?: [];
        }

        if (! is_dir(dirname($cachePath))) {
            mkdir(dirname($cachePath), 0755, true);
        }

        file_put_contents($cachePath, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $records;
    }

    /**
     * Every page of the conversion catalog, straight from IVT.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch(): array
    {
        if (! $this->ivt->login()) {
            throw new RuntimeException('IVT login failed while loading unit conversions: '.$this->ivt->lastError());
        }

        $records = [];
        $page = 1;

        do {
            $response = $this->ivt->unitConversions($page, 100);

            if (! $response) {
                throw new RuntimeException("Unit-conversion API error on page {$page}: ".$this->ivt->lastError());
            }

            $records = array_merge($records, $response['data'] ?? []);
            $totalPages = (int) ($response['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $records;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function index(array $records): void
    {
        foreach ($records as $record) {
            $from = self::normaliseUnit((string) ($record['from_unit_id'] ?? ''));
            $to = self::normaliseUnit((string) ($record['to_unit_id'] ?? ''));
            $rate = (float) ($record['conversion_rate'] ?? 0);

            if ($from === '' || $to === '' || $rate <= 0) {
                continue;
            }

            // A conversion with no items attached says nothing about any
            // particular product, so it is deliberately ignored.
            foreach ($record['items'] ?? [] as $item) {
                $itemId = (string) ($item['item_id'] ?? '');

                if ($itemId === '') {
                    continue;
                }

                $this->graphs[$itemId][$from][$to] = $rate;
                $this->graphs[$itemId][$to][$from] = 1 / $rate;
            }
        }
    }

    /**
     * Strip Vietnamese diacritics and case so "Lọ", "lo" and "LO" all match.
     */
    public static function normaliseUnit(string $unit): string
    {
        $accents = [
            'Á' => 'A', 'À' => 'A', 'Ả' => 'A', 'Ã' => 'A', 'Ạ' => 'A',
            'Â' => 'A', 'Ấ' => 'A', 'Ầ' => 'A', 'Ẩ' => 'A', 'Ẫ' => 'A', 'Ậ' => 'A',
            'Ă' => 'A', 'Ắ' => 'A', 'Ằ' => 'A', 'Ẳ' => 'A', 'Ẵ' => 'A', 'Ặ' => 'A',
            'É' => 'E', 'È' => 'E', 'Ẻ' => 'E', 'Ẽ' => 'E', 'Ẹ' => 'E',
            'Ê' => 'E', 'Ế' => 'E', 'Ề' => 'E', 'Ể' => 'E', 'Ễ' => 'E', 'Ệ' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Ỉ' => 'I', 'Ĩ' => 'I', 'Ị' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ỏ' => 'O', 'Õ' => 'O', 'Ọ' => 'O',
            'Ô' => 'O', 'Ố' => 'O', 'Ồ' => 'O', 'Ổ' => 'O', 'Ỗ' => 'O', 'Ộ' => 'O',
            'Ơ' => 'O', 'Ớ' => 'O', 'Ờ' => 'O', 'Ở' => 'O', 'Ỡ' => 'O', 'Ợ' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Ủ' => 'U', 'Ũ' => 'U', 'Ụ' => 'U',
            'Ư' => 'U', 'Ứ' => 'U', 'Ừ' => 'U', 'Ử' => 'U', 'Ữ' => 'U', 'Ự' => 'U',
            'Ý' => 'Y', 'Ỳ' => 'Y', 'Ỷ' => 'Y', 'Ỹ' => 'Y', 'Ỵ' => 'Y',
            'Đ' => 'D',
        ];

        $unit = strtr(mb_strtoupper(trim($unit)), $accents);

        // Spelling variants of the same unit used by the accountant vs IVT.
        return match ($unit) {
            'G', 'GAM' => 'GR',
            'L' => 'LIT',
            'CHIEC' => 'CAI',
            default => $unit,
        };
    }
}
