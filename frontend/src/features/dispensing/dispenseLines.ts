// Estado del formulario de dispensación, sin reglas de negocio:
// solo arma el cuerpo del POST y avisa errores de digitación evidentes.
// Lo que valida de verdad (vigencia, prescrito, stock, FEFO) es el backend.
import type { DispensationRequest, Prescription, PrescriptionItem } from '../../api/types'

export type Quantities = Record<number, string> // prescription_item_id → texto del input

export function initialQuantities(prescription: Prescription): Quantities {
  return Object.fromEntries(prescription.items.map((item) => [item.id, String(item.quantity_available)]))
}

export interface FormLine {
  item: PrescriptionItem
  quantity: number
}

export interface BuiltForm {
  lines: FormLine[]
  problems: Record<number, string>
}

export function buildLines(prescription: Prescription, quantities: Quantities): BuiltForm {
  const lines: FormLine[] = []
  const problems: Record<number, string> = {}
  for (const item of prescription.items) {
    const raw = (quantities[item.id] ?? '').trim()
    if (raw === '' || raw === '0') continue
    const quantity = Number(raw)
    if (!Number.isInteger(quantity) || quantity < 0) {
      problems[item.id] = 'Cantidad inválida'
    } else if (quantity > item.quantity_available) {
      problems[item.id] = `Máximo ${item.quantity_available}`
    } else {
      lines.push({ item, quantity })
    }
  }
  return { lines, problems }
}

export function toRequest(patientId: number, prescriptionId: number, warehouseId: number, lines: FormLine[]): DispensationRequest {
  return {
    patient_id: patientId,
    prescription_id: prescriptionId,
    warehouse_id: warehouseId,
    items: lines.map((line) => ({ prescription_item_id: line.item.id, quantity: line.quantity })),
  }
}
