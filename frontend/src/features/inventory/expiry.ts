// Clasificación VISUAL de vencimiento para resaltar filas. No decide nada:
// qué lote se dispensa o traslada lo resuelve el backend (RN-01, FEFO).
//
// Niveles: vencido · crítico (≤ 30 días) · en ventana de alerta (≤ alertDays,
// que viene de `alerts.expiry_days` = ALERT_EXPIRY_DAYS del backend) · ok.
export type ExpiryLevel = 'expired' | 'within30' | 'withinAlert' | 'ok'

const DAY_MS = 86_400_000
export const CRITICAL_DAYS = 30
// Solo mientras no ha llegado /alerts (valor por defecto del backend, RN-11).
export const DEFAULT_ALERT_DAYS = 90

// Fecha de hoy (YYYY-MM-DD) en Bogotá. Se prefiere `alerts.today` del servidor.
export function todayInBogota(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota' }).format(now)
}

export function daysUntil(date: string, today: string): number {
  return Math.round((Date.parse(date) - Date.parse(today)) / DAY_MS)
}

export function expiryLevel(expiresAt: string, today: string, isExpired = false, alertDays = DEFAULT_ALERT_DAYS): ExpiryLevel {
  const days = daysUntil(expiresAt, today)
  if (isExpired || days < 0) return 'expired'
  if (days <= CRITICAL_DAYS) return 'within30'
  if (days <= alertDays) return 'withinAlert'
  return 'ok'
}

export function expiryLabel(level: ExpiryLevel, alertDays: number): string {
  switch (level) {
    case 'expired':
      return 'Vencido'
    case 'within30':
      return `Vence en ≤ ${CRITICAL_DAYS} días`
    case 'withinAlert':
      return `Vence en ≤ ${alertDays} días`
    default:
      return ''
  }
}
