<?php

namespace Tests\Support;

use App\Domain\Dispensing\DispenseData;
use App\Domain\Dispensing\DispenseItemData;
use App\Models\Lot;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;

/**
 * Escenario mínimo para probar dispensaciones: una bodega, un producto con
 * varios lotes en existencia, un paciente con una prescripción vigente y los
 * usuarios típicos (auxiliar, dos regentes).
 */
final class DispensingScenario
{
    /** @var list<Lot> */
    public array $lots = [];

    public function __construct(
        public Warehouse $warehouse,
        public Product $product,
        public Patient $patient,
        public Prescription $prescription,
        public PrescriptionItem $item,
        public User $auxiliar,
        public User $regente,
        public User $regente2,
    ) {}

    /**
     * @param  list<array{0: int, 1: int}>  $lots  [días para vencer (negativo = vencido), cantidad]
     */
    public static function make(array $lots = [[30, 10]], int $prescribed = 10, bool $controlled = false): self
    {
        $product = $controlled ? Product::factory()->controlled()->create() : Product::factory()->create();
        $prescription = Prescription::factory()->create();

        $scenario = new self(
            Warehouse::factory()->create(),
            $product,
            $prescription->patient()->firstOrFail(),
            $prescription,
            PrescriptionItem::factory()->create([
                'prescription_id' => $prescription->id,
                'product_id' => $product->id,
                'quantity_prescribed' => $prescribed,
            ]),
            User::factory()->auxiliar()->create(),
            User::factory()->regente()->create(),
            User::factory()->regente()->create(),
        );

        foreach ($lots as [$days, $quantity]) {
            $scenario->addLot($days, $quantity);
        }

        return $scenario;
    }

    public function addLot(int $daysToExpire, int $quantity, ?Warehouse $warehouse = null): Lot
    {
        $lot = Lot::factory()->for($this->product)->expiresInDays($daysToExpire)->create();
        Stock::factory()->create([
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'lot_id' => $lot->id,
            'quantity' => $quantity,
        ]);
        $this->lots[] = $lot;

        return $lot;
    }

    public function data(int $quantity): DispenseData
    {
        return new DispenseData(
            $this->patient->id,
            $this->prescription->id,
            $this->warehouse->id,
            [new DispenseItemData($this->item->id, $quantity)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(int $quantity): array
    {
        return [
            'patient_id' => $this->patient->id,
            'prescription_id' => $this->prescription->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [['prescription_item_id' => $this->item->id, 'quantity' => $quantity]],
        ];
    }

    public function stockOf(Lot $lot): int
    {
        return (int) Stock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('lot_id', $lot->id)
            ->value('quantity');
    }

    public function totalStock(): int
    {
        return (int) Stock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->sum('quantity');
    }
}
