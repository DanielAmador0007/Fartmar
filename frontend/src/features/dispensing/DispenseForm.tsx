import { useState } from 'react'
import type { Dispensation, Prescription } from '../../api/types'
import { ErrorAlert, Loading } from '../../components/ui'
import { useSubmitGuard } from '../../lib/useSubmitGuard'
import { useCatalog } from '../catalog/useCatalog'
import { WarehouseSelect } from '../catalog/WarehouseSelect'
import { buildLines, initialQuantities, toRequest, type Quantities } from './dispenseLines'
import { useCreateDispensation, useFefoPreviews, type PreviewLine } from './dispensingApi'
import { FefoPreviewList } from './DispensationView'

interface Props {
  patientId: number
  prescription: Prescription
  onDone: (dispensation: Dispensation) => void
}

// Paso a paso: cantidades → vista previa FEFO → Confirmar.
export function DispenseForm({ patientId, prescription, onDone }: Props) {
  const catalog = useCatalog()
  const [selectedWarehouse, setSelectedWarehouse] = useState<number | null>(null)
  const [quantities, setQuantities] = useState<Quantities>(() => initialQuantities(prescription))
  const [previewFor, setPreviewFor] = useState<{ warehouseId: number; lines: PreviewLine[] } | null>(null)
  const create = useCreateDispensation()
  const guard = useSubmitGuard()

  // Sin bodega por defecto: dispensar desde la bodega equivocada descuenta stock ajeno (M1).
  const warehouseId = selectedWarehouse
  const { lines, problems } = buildLines(prescription, quantities)
  const currentPreviewLines: PreviewLine[] = lines.map((l) => ({ productId: l.item.product.id, quantity: l.quantity }))

  // La vista previa solo vale si corresponde a lo que hay ahora en pantalla.
  const previewIsCurrent =
    previewFor !== null && JSON.stringify(previewFor) === JSON.stringify({ warehouseId, lines: currentPreviewLines })
  const previews = useFefoPreviews(previewFor?.warehouseId ?? null, previewFor?.lines ?? [])
  const previewOk = previewIsCurrent && previews.length > 0 && previews.every((p) => p.isSuccess)
  const hasProblems = Object.keys(problems).length > 0

  function showPreview() {
    if (warehouseId === null || lines.length === 0 || hasProblems) return
    setPreviewFor({ warehouseId, lines: currentPreviewLines })
  }

  function confirm() {
    if (!previewOk || warehouseId === null) return
    void guard(() => create.mutateAsync(toRequest(patientId, prescription.id, warehouseId, lines)).then(onDone))
  }

  if (catalog.isPending) return <Loading label="Cargando bodegas…" />

  return (
    <section className="card" aria-label="Dispensar">
      <h2>Dispensar {prescription.number}</h2>
      <ErrorAlert error={catalog.error} />
      <WarehouseSelect
        label="Bodega"
        value={warehouseId}
        options={catalog.data?.warehouses ?? []}
        onChange={(id) => setSelectedWarehouse(id)}
        allowEmpty="Seleccione…"
        required
      />
      {warehouseId === null && <p className="muted">Elige la bodega desde la que se entrega.</p>}
      <table>
        <thead>
          <tr>
            <th>Medicamento</th>
            <th className="num">Prescrito</th>
            <th className="num">Entregado</th>
            <th className="num">Reservado</th>
            <th className="num">Pendiente</th>
            <th>A entregar</th>
          </tr>
        </thead>
        <tbody>
          {prescription.items.map((item) => (
            <tr key={item.id}>
              <td>
                {item.product.name} {item.product.is_controlled && <span className="badge badge-red">Control especial</span>}
              </td>
              <td className="num">{item.quantity_prescribed}</td>
              <td className="num">{item.quantity_dispensed}</td>
              <td className="num">{item.quantity_reserved}</td>
              <td className="num">{item.quantity_available}</td>
              <td>
                <input
                  type="number"
                  min={0}
                  max={item.quantity_available}
                  aria-label={`Cantidad de ${item.product.name}`}
                  value={quantities[item.id] ?? ''}
                  disabled={item.quantity_available === 0}
                  onChange={(e) => setQuantities((q) => ({ ...q, [item.id]: e.target.value }))}
                />
                {problems[item.id] && <span className="field-error"> {problems[item.id]}</span>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="actions">
        <button type="button" onClick={showPreview} disabled={lines.length === 0 || hasProblems || warehouseId === null}>
          Ver vista previa FEFO
        </button>
        <button type="button" className="primary" onClick={confirm} disabled={!previewOk || create.isPending}>
          {create.isPending ? 'Dispensando…' : 'Confirmar dispensación'}
        </button>
      </div>
      {lines.length === 0 && !hasProblems && <p className="muted">Ingresa al menos una cantidad.</p>}
      {previewFor && !previewIsCurrent && <p className="muted">Cambiaste los datos: vuelve a ver la vista previa antes de confirmar.</p>}
      <ErrorAlert error={create.error} action="dispensar" />

      {previewFor && previewIsCurrent && (
        <FefoPreviewList
          results={previews}
          names={lines.map((l) => `${l.item.product.name} · ${l.quantity} unidades`)}
        />
      )}
    </section>
  )
}
