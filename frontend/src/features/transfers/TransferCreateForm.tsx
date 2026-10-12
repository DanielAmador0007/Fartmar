import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { ErrorAlert, Loading } from '../../components/ui'
import { useSubmitGuard } from '../../lib/useSubmitGuard'
import { useCatalog } from '../catalog/useCatalog'
import { ProductSelect, WarehouseSelect } from '../catalog/WarehouseSelect'
import { useCreateTransfer } from './transfersApi'

interface Line {
  productId: number | null
  quantity: string
}

// Crea el traslado en BORRADOR (no mueve stock; los lotes se eligen al despachar).
export function TransferCreateForm() {
  const catalog = useCatalog()
  const navigate = useNavigate()
  const create = useCreateTransfer()
  const guard = useSubmitGuard()
  const [origin, setOrigin] = useState<number | null>(null)
  const [destination, setDestination] = useState<number | null>(null)
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<Line[]>([{ productId: null, quantity: '' }])

  const items = lines
    .filter((l) => l.productId !== null && Number.isInteger(Number(l.quantity)) && Number(l.quantity) >= 1)
    .map((l) => ({ product_id: l.productId as number, quantity: Number(l.quantity) }))
  const sameWarehouse = origin !== null && origin === destination
  const canSubmit = origin !== null && destination !== null && !sameWarehouse && items.length === lines.length && items.length > 0

  function updateLine(index: number, patch: Partial<Line>) {
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return
    void guard(() =>
      create
        .mutateAsync({
          origin_warehouse_id: origin as number,
          destination_warehouse_id: destination as number,
          notes: notes.trim() || null,
          items,
        })
        .then((transfer) => navigate(`/traslados/${transfer.id}`)),
    )
  }

  if (catalog.isPending) return <Loading label="Cargando catálogo…" />
  const warehouses = catalog.data?.warehouses ?? []
  const products = catalog.data?.products ?? []

  return (
    <form className="card" onSubmit={handleSubmit} aria-label="Nuevo traslado">
      <h2>Nuevo traslado</h2>
      <ErrorAlert error={catalog.error} />
      <div className="inline-form">
        <WarehouseSelect label="Origen" value={origin} options={warehouses} onChange={setOrigin} allowEmpty="Seleccione…" required />
        <WarehouseSelect label="Destino" value={destination} options={warehouses} onChange={setDestination} allowEmpty="Seleccione…" required />
      </div>
      {sameWarehouse && <p className="field-error">El origen y el destino deben ser distintos.</p>}
      {lines.map((line, index) => (
        <div key={index} className="inline-form">
          <ProductSelect
            label={`Producto ${index + 1}`}
            value={line.productId}
            options={products}
            onChange={(id) => updateLine(index, { productId: id })}
            allowEmpty="Seleccione…"
            required
          />
          <label>
            Cantidad
            <input type="number" min={1} required value={line.quantity} onChange={(e) => updateLine(index, { quantity: e.target.value })} />
          </label>
          {lines.length > 1 && (
            <button type="button" onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>
              Quitar
            </button>
          )}
        </div>
      ))}
      <button type="button" onClick={() => setLines((current) => [...current, { productId: null, quantity: '' }])}>
        Agregar producto
      </button>
      <label>
        Observaciones
        <textarea maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </label>
      <ErrorAlert error={create.error} action="crear traslados" />
      <button type="submit" className="primary" disabled={!canSubmit || create.isPending}>
        {create.isPending ? 'Creando…' : 'Crear borrador'}
      </button>
    </form>
  )
}
