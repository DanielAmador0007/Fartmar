<?php

namespace Tests\Support;

use App\Domain\Inventory\StockService;
use App\Domain\Transfers\TransferData;
use App\Domain\Transfers\TransferItemData;
use App\Domain\Transfers\TransferService;
use App\Enums\KardexType;
use App\Models\Lot;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Escenario mínimo para traslados: bodega origen y destino, un producto con
 * lotes en el origen (cargados con StockService, así hay kardex ENTRADA y la
 * suma del kardex cuadra con stocks.quantity), un auxiliar y dos regentes.
 */
final class TransferScenario
{
    /** @var list<Lot> */
    public array $lots = [];

    public function __construct(
        public Warehouse $origin,
        public Warehouse $destination,
        public Product $product,
        public User $auxiliar,
        public User $regente,
        public User $regente2,
    ) {}

    /**
     * @param  list<array{0: int, 1: int}>  $lots  [días para vencer (negativo = vencido), cantidad] en el ORIGEN
     */
    public static function make(array $lots = [[30, 10]]): self
    {
        $scenario = new self(
            Warehouse::factory()->create(),
            Warehouse::factory()->create(),
            Product::factory()->create(),
            User::factory()->auxiliar()->create(),
            User::factory()->regente()->create(),
            User::factory()->regente()->create(),
        );

        foreach ($lots as [$days, $quantity]) {
            $scenario->lots[] = $scenario->addLot($scenario->product, $days, $quantity);
        }

        return $scenario;
    }

    /**
     * Lote nuevo con existencias en una bodega (origen por defecto), por el servicio real.
     */
    public function addLot(Product $product, int $daysToExpire, int $quantity, ?Warehouse $warehouse = null): Lot
    {
        $lot = Lot::factory()->for($product)->expiresInDays($daysToExpire)->create();

        DB::transaction(fn () => app(StockService::class)->increase(
            ($warehouse ?? $this->origin)->id, $lot, $quantity, KardexType::Entrada, $this->regente,
        ));

        return $lot;
    }

    /**
     * @param  array<int, int>  $products  product_id => cantidad (por defecto el producto del escenario)
     */
    public function data(int $quantity = 5, array $products = [], ?string $notes = null): TransferData
    {
        $products = $products === [] ? [$this->product->id => $quantity] : $products;
        $items = [];
        foreach ($products as $productId => $qty) {
            $items[] = new TransferItemData($productId, $qty);
        }

        return new TransferData($this->origin->id, $this->destination->id, $items, $notes);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(int $quantity = 5): array
    {
        return [
            'origin_warehouse_id' => $this->origin->id,
            'destination_warehouse_id' => $this->destination->id,
            'notes' => 'Reposición semanal',
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
        ];
    }

    /** Traslado creado y solicitado por el auxiliar. */
    public function requested(int $quantity = 5): Transfer
    {
        $service = app(TransferService::class);

        return $service->submit($service->create($this->auxiliar, $this->data($quantity)), $this->auxiliar);
    }

    /** Solicitado por el auxiliar y aprobado por un regente. */
    public function approved(int $quantity = 5): Transfer
    {
        return app(TransferService::class)->approve($this->requested($quantity), $this->regente);
    }

    /** Aprobado y despachado por el auxiliar. */
    public function dispatched(int $quantity = 5): Transfer
    {
        return app(TransferService::class)->dispatch($this->approved($quantity), $this->auxiliar);
    }

    public function stockAt(Warehouse $warehouse, Lot $lot): int
    {
        return (int) Stock::query()->where('warehouse_id', $warehouse->id)->where('lot_id', $lot->id)->value('quantity');
    }

    public function totalAt(Warehouse $warehouse, ?Product $product = null): int
    {
        return (int) Stock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', ($product ?? $this->product)->id)
            ->sum('quantity');
    }
}
