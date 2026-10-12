import { useState, type FormEvent } from 'react'
import type { Stock } from '../../api/types'
import { ErrorAlert, SuccessAlert } from '../../components/ui'
import { useSubmitGuard } from '../../lib/useSubmitGuard'
import { useAdjustInventory } from './inventoryApi'

// Ajuste manual (solo regente): cantidad con signo y motivo obligatorio.
// La API no es idempotente aquí (S-61): botón deshabilitado + guardia anti doble clic.
export function AdjustmentForm({ stocks }: { stocks: Stock[] }) {
  const [stockId, setStockId] = useState<number | null>(null)
  const [quantity, setQuantity] = useState('')
  const [reason, setReason] = useState('')
  const mutation = useAdjustInventory()
  const guard = useSubmitGuard()

  const stock = stocks.find((s) => s.id === stockId) ?? null
  const qty = Number(quantity)
  const valid = stock !== null && Number.isInteger(qty) && qty !== 0 && reason.trim().length >= 5

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (!valid || !stock) return
    void guard(() =>
      mutation
        .mutateAsync({ warehouse_id: stock.warehouse.id, lot_id: stock.lot.id, quantity: qty, reason: reason.trim() })
        .then(() => {
          setQuantity('')
          setReason('')
        }),
    )
  }

  return (
    <form className="card" onSubmit={handleSubmit} aria-label="Ajuste de inventario">
      <h2>Ajuste de inventario</h2>
      <label>
        Existencia (bodega · producto · lote)
        <select value={stockId ?? ''} onChange={(e) => setStockId(e.target.value ? Number(e.target.value) : null)}>
          <option value="">Seleccione…</option>
          {stocks.map((s) => (
            <option key={s.id} value={s.id}>
              {s.warehouse.code} · {s.product.name} · {s.lot.lot_number} (hay {s.quantity})
            </option>
          ))}
        </select>
      </label>
      <div className="inline-form">
        <label>
          Cantidad (+ suma, − resta)
          <input type="number" value={quantity} onChange={(e) => setQuantity(e.target.value)} />
        </label>
        <label className="grow">
          Motivo (obligatorio, mínimo 5 caracteres)
          <input value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
        </label>
      </div>
      <ErrorAlert error={mutation.error} action="hacer ajustes de inventario" />
      {mutation.isSuccess && (
        <SuccessAlert>
          Ajuste registrado. Nuevo saldo del lote: {mutation.data.balance_after}.
        </SuccessAlert>
      )}
      <button type="submit" className="primary" disabled={!valid || mutation.isPending}>
        {mutation.isPending ? 'Registrando…' : 'Registrar ajuste'}
      </button>
    </form>
  )
}
