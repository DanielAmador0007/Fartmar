// Idempotency-Key de la dispensación (RN-09).
//
// Regla: UNA clave por intento lógico de dispensación.
// - Reintentar el MISMO contenido (p. ej. tras un error de red o un 409 de
//   concurrencia) reutiliza la clave: si el primer envío sí llegó, el backend
//   devuelve la dispensación original sin descontar stock otra vez.
// - Si el usuario cambia el contenido (otra cantidad, otra bodega), es otro
//   intento: clave nueva (si no, el backend respondería IDEMPOTENCIA_CONFLICTO).
// - Tras un éxito o un IDEMPOTENCIA_CONFLICTO, se descarta la clave.
export interface IdempotencyTracker {
  keyFor: (payload: unknown) => string
  reset: () => void
}

export function createIdempotencyTracker(generate: () => string = () => crypto.randomUUID()): IdempotencyTracker {
  let current: { key: string; fingerprint: string } | null = null
  return {
    keyFor(payload) {
      const fingerprint = JSON.stringify(payload)
      if (!current || current.fingerprint !== fingerprint) {
        current = { key: generate(), fingerprint }
      }
      return current.key
    },
    reset() {
      current = null
    },
  }
}
