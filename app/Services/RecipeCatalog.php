<?php

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * The IVT recipe catalog, used to tell a raw material from something the
 * kitchen produced.
 *
 * An item that owns a recipe is an *output* of processing — a topping, a
 * concentrate, a jelly. Its ingredients were already issued separately (XSD),
 * so counting the output again on top of them would bill the same physical
 * material twice. Accounting-wise the distinction is the same one the books
 * make: raw materials sit in TK 152, processed goods do not.
 */
class RecipeCatalog
{
    private const CACHE_TTL_SECONDS = 86400;

    /**
     * Set when the refresh failed and the on-disk cache was used past its TTL.
     */
    private ?string $staleWarning = null;

    /** @var array<string, true> item_id => has a recipe */
    private array $processed = [];

    /**
     * item_id => the recipe for ONE unit of that item.
     *
     * IVT normalises every recipe to a single output unit, so the quantities
     * below are "per 1 unit" and scale by plain multiplication.
     *
     * @var array<string, array{unit: string, amount: float, details: array<int, array{item_id: string, item_name: string, unit: string, unit_name: string, quantity: float, amount: float}>}>
     */
    private array $recipes = [];

    private bool $loaded = false;

    public function __construct(private readonly IvtClient $ivt) {}

    /**
     * Why the recipe list may be out of date, or null when it is fresh.
     */
    public function staleWarning(): ?string
    {
        return $this->staleWarning;
    }

    /**
     * True when the item is produced by a recipe rather than bought as-is.
     */
    public function isProcessed(string $itemId): bool
    {
        $this->load();

        return isset($this->processed[$itemId]);
    }

    /**
     * The recipe for one unit of an item, or null when it is a raw material.
     *
     * @return array{unit: string, amount: float, details: array<int, array{item_id: string, item_name: string, unit: string, unit_name: string, quantity: float, amount: float}>}|null
     */
    public function recipeFor(string $itemId): ?array
    {
        $this->load();

        return $this->recipes[$itemId] ?? null;
    }

    /**
     * Every item_id that owns a recipe.
     *
     * @return string[]
     */
    public function processedItemIds(): array
    {
        $this->load();

        return array_keys($this->processed);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        foreach ($this->records() as $record) {
            $itemId = (string) ($record['item_id'] ?? '');

            if ($itemId === '') {
                continue;
            }

            $this->processed[$itemId] = true;

            $details = [];

            foreach ($record['recipe_details'] ?? [] as $detail) {
                $ingredient = (string) ($detail['item_id'] ?? '');

                if ($ingredient === '') {
                    continue;
                }

                $details[] = [
                    'item_id' => $ingredient,
                    'item_name' => (string) ($detail['item_name'] ?? $ingredient),
                    'unit' => (string) ($detail['unit_id'] ?? ''),
                    'unit_name' => (string) ($detail['unit_name'] ?? ''),
                    'quantity' => (float) ($detail['quantity'] ?? 0),
                    // Cost of this ingredient in one unit of the output. Used
                    // only as a weight, never as money in its own right: the
                    // recipe's prices are a snapshot, while the summary carries
                    // the cost actually booked.
                    'amount' => (float) ($detail['amount'] ?? 0),
                ];
            }

            $this->recipes[$itemId] = [
                'unit' => (string) ($record['unit_id'] ?? ''),
                'amount' => (float) ($record['amount'] ?? 0),
                'details' => $details,
            ];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        $cachePath = storage_path('app/ivt-cache/recipes.json');
        $cached = is_file($cachePath);

        if ($cached && (time() - filemtime($cachePath)) < self::CACHE_TTL_SECONDS) {
            return json_decode((string) file_get_contents($cachePath), true) ?: [];
        }

        // Same bargain as the unit-conversion catalog: recipes change slowly,
        // so an unreachable IVT should not abort a reconciliation that has a
        // usable cache on disk. The fall back is recorded, never silent.
        try {
            $records = $this->fetch();
        } catch (Throwable $e) {
            if (! $cached) {
                throw $e;
            }

            $age = (int) round((time() - filemtime($cachePath)) / 3600);
            $this->staleWarning = "Không làm mới được công thức từ IVT ({$e->getMessage()}); "
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
     * Every page of the recipe list, straight from IVT.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch(): array
    {
        if (! $this->ivt->login()) {
            throw new RuntimeException('IVT login failed while loading recipes: '.$this->ivt->lastError());
        }

        $records = [];
        $page = 1;

        do {
            $response = $this->ivt->recipes($page, 100);

            if (! $response) {
                throw new RuntimeException("Recipe API error on page {$page}: ".$this->ivt->lastError());
            }

            $records = array_merge($records, $response['data'] ?? []);
            $totalPages = (int) ($response['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $records;
    }
}
