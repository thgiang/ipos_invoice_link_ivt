<?php

namespace App\Services;

/**
 * Rewrites a processed item into the raw materials it was made from.
 *
 * The kitchen books a semi-finished good as a line of its own — "Cốt TS Khoai
 * môn", 12.929.595 ML — which is a name the tax book has never heard of. What
 * the books do carry is what went into it: trà nhài, sữa đặc, bột sữa. Exploding
 * the recipe restates the former as the latter so both sides speak about the
 * same materials.
 *
 * Two properties are held on to throughout, because a reconciliation is
 * worthless without them:
 *
 *  - **Money is conserved.** The cost booked on the processed line is split
 *    across its ingredients in the proportions the recipe implies, so the grand
 *    total after exploding equals the total before, to the đồng. The recipe's
 *    own prices are used as weights only — they are a snapshot, whereas the
 *    summary carries the cost actually booked.
 *  - **Nothing is invented.** An item whose unit cannot be converted, or whose
 *    recipe carries no cost to weight by, is left standing as itself and
 *    reported in issues() rather than exploded on a guessed factor.
 */
class RecipeExploder
{
    /**
     * Guards against a recipe that reaches itself (A → B → A), which would
     * otherwise recurse until the stack gives out.
     */
    private const MAX_DEPTH = 12;

    /** @var array<string, true> */
    private array $issues = [];

    public function __construct(
        private readonly RecipeCatalog $recipes,
        private readonly UnitConversionCatalog $units,
    ) {}

    /**
     * Raw materials making up a quantity of one item.
     *
     * @param  array<string, string>  $targetUnits  item_id => unit the caller already counts that item in, so an exploded ingredient merges with the row that is already on screen instead of forming a second row in the recipe's unit
     * @return array<int, array{item_id: string, item_name: string, unit: string, unit_name: string, qty: float, cost: float}>
     */
    public function explode(
        string $itemId,
        string $itemName,
        string $unit,
        string $unitName,
        float $qty,
        float $cost,
        array $targetUnits = [],
    ): array {
        return $this->walk($itemId, $itemName, $unit, $unitName, $qty, $cost, $targetUnits, []);
    }

    /**
     * True when the item is something the kitchen produced.
     */
    public function isProcessed(string $itemId): bool
    {
        return $this->recipes->isProcessed($itemId);
    }

    /**
     * Items that could not be exploded, and why. Reported on screen rather than
     * swallowed: a silently un-exploded topping looks exactly like a raw
     * material once it is in the table.
     *
     * @return string[]
     */
    public function issues(): array
    {
        return array_keys($this->issues);
    }

    /**
     * @param  array<string, string>  $targetUnits
     * @param  array<string, true>  $stack
     * @return array<int, array{item_id: string, item_name: string, unit: string, unit_name: string, qty: float, cost: float}>
     */
    private function walk(
        string $itemId,
        string $itemName,
        string $unit,
        string $unitName,
        float $qty,
        float $cost,
        array $targetUnits,
        array $stack,
    ): array {
        $self = [[
            'item_id' => $itemId,
            'item_name' => $itemName,
            'unit' => $unit,
            'unit_name' => $unitName,
            'qty' => $qty,
            'cost' => $cost,
        ]];

        $recipe = $this->recipes->recipeFor($itemId);

        if ($recipe === null) {
            return $this->inTargetUnit($self, $targetUnits);
        }

        if (isset($stack[$itemId]) || count($stack) >= self::MAX_DEPTH) {
            $this->issues[$itemId.' — công thức lặp vòng, dừng ở đây'] = true;

            return $self;
        }

        // The summary counts the item in whatever unit IVT stocks it; the recipe
        // is written per one unit of its own. Line them up before scaling.
        $perUnit = $this->units->factor($itemId, $recipe['unit'], $unit);

        if ($perUnit === null || $perUnit == 0.0) {
            $this->issues[$itemId." — không quy đổi được {$unit} sang {$recipe['unit']} của công thức"] = true;

            return $self;
        }

        $outputQty = $qty / $perUnit;
        $weight = array_sum(array_column($recipe['details'], 'amount'));

        if ($weight <= 0.0) {
            $this->issues[$itemId.' — công thức không có giá trị nào để chia tiền theo'] = true;

            return $self;
        }

        $stack[$itemId] = true;
        $parts = [];

        foreach ($recipe['details'] as $detail) {
            $parts[] = $this->walk(
                $detail['item_id'],
                $detail['item_name'],
                $detail['unit'],
                $detail['unit_name'],
                $outputQty * $detail['quantity'],
                $cost * $detail['amount'] / $weight,
                $targetUnits,
                $stack,
            );
        }

        return array_merge(...$parts);
    }

    /**
     * Restate leaves in the unit the caller already uses for that item. Failing
     * to convert is not an error — the ingredient simply stays in the recipe's
     * unit and shows up as its own row.
     *
     * @param  array<int, array{item_id: string, item_name: string, unit: string, unit_name: string, qty: float, cost: float}>  $leaves
     * @param  array<string, string>  $targetUnits
     * @return array<int, array{item_id: string, item_name: string, unit: string, unit_name: string, qty: float, cost: float}>
     */
    private function inTargetUnit(array $leaves, array $targetUnits): array
    {
        foreach ($leaves as $i => $leaf) {
            $target = $targetUnits[$leaf['item_id']] ?? null;

            if ($target === null || $target === $leaf['unit']) {
                continue;
            }

            $factor = $this->units->factor($leaf['item_id'], $target, $leaf['unit']);

            if ($factor === null || $factor == 0.0) {
                $this->issues[$leaf['item_id']." — nguyên liệu tính theo {$leaf['unit']}, tồn kho theo {$target}, không quy đổi được nên để riêng dòng"] = true;

                continue;
            }

            $leaves[$i]['qty'] = $leaf['qty'] / $factor;
            $leaves[$i]['unit'] = $target;
            $leaves[$i]['unit_name'] = '';
        }

        return $leaves;
    }
}
