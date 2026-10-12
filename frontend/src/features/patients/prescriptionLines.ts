// Arma el cuerpo de POST /prescriptions y avisa errores de digitación
// evidentes. Las reglas completas (fecha posterior a hoy y hasta un año,
// sin productos repetidos, 1–20 líneas) las valida la API (S-70).
import type { PrescriptionRequest, Product } from '../../api/types'
import { todayInBogota } from '../inventory/expiry'

export const MAX_LINES = 20
export const MAX_QUANTITY = 10_000
const DAY_MS = 86_400_000

export interface PrescriptionLine {
  product: Product
  quantity: string
  instructions: string
}

export function addDays(date: string, days: number): string {
  return new Date(Date.parse(date) + days * DAY_MS).toISOString().slice(0, 10)
}

// Rango del input de fecha: mañana .. hoy + 365 (hoy en Bogotá).
export function validUntilRange(today = todayInBogota()) {
  return { min: addDays(today, 1), max: addDays(today, 365), suggested: addDays(today, 30) }
}

export function buildPrescription(
  patientId: number,
  validUntil: string,
  lines: PrescriptionLine[],
): { request: PrescriptionRequest | null; problems: string[] } {
  const problems: string[] = []
  if (!validUntil) problems.push('Indica hasta cuándo es válida la prescripción.')
  if (lines.length === 0) problems.push('Agrega al menos un medicamento.')
  if (lines.length > MAX_LINES) problems.push(`Máximo ${MAX_LINES} medicamentos por prescripción.`)
  for (const line of lines) {
    const quantity = Number(line.quantity)
    if (line.quantity.trim() === '' || !Number.isInteger(quantity) || quantity < 1 || quantity > MAX_QUANTITY) {
      problems.push(`Cantidad inválida para ${line.product.name} (entre 1 y ${MAX_QUANTITY}).`)
    }
    if (line.instructions.length > 255) problems.push(`Indicaciones de ${line.product.name}: máximo 255 caracteres.`)
  }
  if (problems.length > 0) return { request: null, problems }
  return {
    request: {
      patient_id: patientId,
      valid_until: validUntil,
      items: lines.map((l) => ({
        product_id: l.product.id,
        quantity: Number(l.quantity),
        instructions: l.instructions.trim() || null,
      })),
    },
    problems,
  }
}
