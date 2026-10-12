import { useState, type FormEvent } from 'react'
import type { Transfer } from '../../api/types'

interface Props {
  transfer: Transfer
  busy: boolean
  onSubmit: (lots: { transfer_item_lot_id: number; quantity_received: number }[]) => void
}

// Recepción única con TODOS los lotes despachados (S-55). Por defecto se
// propone lo despachado; lo que falte queda como discrepancia.
export function ReceiveForm({ transfer, busy, onSubmit }: Props) {
  const lots = (transfer.items ?? []).flatMap((item) => item.lots.map((lot) => ({ ...lot, productName: item.product.name })))
  const [received, setReceived] = useState<Record<number, string>>(() =>
    Object.fromEntries(lots.map((lot) => [lot.transfer_item_lot_id, String(lot.quantity_dispatched)])),
  )

  const parsed = lots.map((lot) => {
    const value = Number(received[lot.transfer_item_lot_id])
    const valid = (received[lot.transfer_item_lot_id] ?? '') !== '' && Number.isInteger(value) && value >= 0 && value <= lot.quantity_dispatched
    return { lot, value, valid }
  })
  const allValid = parsed.every((p) => p.valid)
  const missing = parsed.reduce((sum, p) => sum + (p.valid ? p.lot.quantity_dispatched - p.value : 0), 0)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (!allValid || busy) return
    onSubmit(parsed.map((p) => ({ transfer_item_lot_id: p.lot.transfer_item_lot_id, quantity_received: p.value })))
  }

  return (
    <form onSubmit={handleSubmit} aria-label="Recibir traslado">
      <h4>Recepción por lote</h4>
      <table className="compact">
        <thead>
          <tr>
            <th>Producto</th>
            <th>Lote</th>
            <th>Vence</th>
            <th className="num">Despachado</th>
            <th>Recibido</th>
          </tr>
        </thead>
        <tbody>
          {parsed.map(({ lot, valid }) => (
            <tr key={lot.transfer_item_lot_id}>
              <td>{lot.productName}</td>
              <td>{lot.lot_number}</td>
              <td>{lot.expires_at}</td>
              <td className="num">{lot.quantity_dispatched}</td>
              <td>
                <input
                  type="number"
                  min={0}
                  max={lot.quantity_dispatched}
                  aria-label={`Recibido del lote ${lot.lot_number}`}
                  value={received[lot.transfer_item_lot_id] ?? ''}
                  onChange={(e) => setReceived((r) => ({ ...r, [lot.transfer_item_lot_id]: e.target.value }))}
                />
                {!valid && <span className="field-error"> Entre 0 y {lot.quantity_dispatched}</span>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {allValid && missing > 0 && (
        <p className="alert alert-warning">Faltan {missing} unidades: quedarán como discrepancia pendiente para un regente.</p>
      )}
      <button type="submit" className="primary" disabled={busy || !allValid}>
        {busy ? 'Registrando…' : 'Registrar recepción'}
      </button>
    </form>
  )
}
