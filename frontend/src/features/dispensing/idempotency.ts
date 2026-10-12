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

// UUID v4. crypto.randomUUID solo existe en contextos seguros (HTTPS o
// localhost); si se abre por http://IP en la red de la farmacia no está,
// y se arma con crypto.getRandomValues (disponible siempre).
export function newUuid(cryptoImpl: Pick<Crypto, 'getRandomValues'> & { randomUUID?: () => string } = crypto): string {
  if (typeof cryptoImpl.randomUUID === 'function') return cryptoImpl.randomUUID()
  const bytes = cryptoImpl.getRandomValues(new Uint8Array(16))
  bytes[6] = (bytes[6] & 0x0f) | 0x40 // versión 4
  bytes[8] = (bytes[8] & 0x3f) | 0x80 // variante RFC 4122
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
  return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-')
}

export function createIdempotencyTracker(generate: () => string = () => newUuid()): IdempotencyTracker {
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
