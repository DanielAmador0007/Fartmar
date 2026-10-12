// Clasificación VISUAL de vencimiento para resaltar filas. No decide nada:
// qué lote se dispensa o traslada lo resuelve el backend (RN-01, FEFO).
export type ExpiryLevel = 'expired' | 'within30' | 'within90' | 'ok'

const DAY_MS = 86_400_000

// Fecha de hoy (YYYY-MM-DD) en Bogotá. Se prefiere `alerts.today` del servidor.
export function todayInBogota(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota' }).format(now)
}

export function daysUntil(date: string, today: string): number {
  return Math.round((Date.parse(date) - Date.parse(today)) / DAY_MS)
}

export function expiryLevel(expiresAt: string, today: string, isExpired = false): ExpiryLevel {
  const days = daysUntil(expiresAt, today)
  if (isExpired || days < 0) return 'expired'
  if (days <= 30) return 'within30'
  if (days <= 90) return 'within90'
  return 'ok'
}

export const EXPIRY_LABEL: Record<ExpiryLevel, string> = {
  expired: 'Vencido',
  within30: 'Vence en ≤ 30 días',
  within90: 'Vence en ≤ 90 días',
  ok: '',
}
