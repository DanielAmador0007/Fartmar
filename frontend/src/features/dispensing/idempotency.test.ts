import { describe, expect, it } from 'vitest'
import { createIdempotencyTracker, newUuid } from './idempotency'

function sequence() {
  let n = 0
  return () => `key-${++n}`
}

describe('createIdempotencyTracker', () => {
  it('reutiliza la clave mientras el contenido no cambie', () => {
    const tracker = createIdempotencyTracker(sequence())
    expect(tracker.keyFor({ a: 1 })).toBe('key-1')
    expect(tracker.keyFor({ a: 1 })).toBe('key-1')
  })

  it('genera otra clave si cambia el contenido', () => {
    const tracker = createIdempotencyTracker(sequence())
    tracker.keyFor({ a: 1 })
    expect(tracker.keyFor({ a: 2 })).toBe('key-2')
  })

  it('genera otra clave después de reset (éxito o conflicto)', () => {
    const tracker = createIdempotencyTracker(sequence())
    tracker.keyFor({ a: 1 })
    tracker.reset()
    expect(tracker.keyFor({ a: 1 })).toBe('key-2')
  })

  it('por defecto usa crypto.randomUUID', () => {
    const key = createIdempotencyTracker().keyFor({})
    expect(key).toMatch(/^[0-9a-f-]{36}$/)
  })
})

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

describe('newUuid', () => {
  it('usa randomUUID si existe', () => {
    expect(newUuid({ randomUUID: () => 'desde-randomUUID', getRandomValues: crypto.getRandomValues.bind(crypto) })).toBe('desde-randomUUID')
  })

  it('sin randomUUID (contexto no seguro) arma un UUID v4 con getRandomValues', () => {
    const fallback = { getRandomValues: crypto.getRandomValues.bind(crypto) }
    const a = newUuid(fallback)
    const b = newUuid(fallback)
    expect(a).toMatch(UUID_V4)
    expect(b).toMatch(UUID_V4)
    expect(a).not.toBe(b)
  })

  it('fija versión y variante aunque los bytes aleatorios sean 0xff', () => {
    const allOnes = { getRandomValues: <T extends ArrayBufferView | null>(array: T) => (new Uint8Array((array as unknown as Uint8Array).buffer).fill(0xff), array) }
    expect(newUuid(allOnes)).toBe('ffffffff-ffff-4fff-bfff-ffffffffffff')
  })

  it('el formato cumple el patrón de Idempotency-Key de la API', () => {
    expect(newUuid({ getRandomValues: crypto.getRandomValues.bind(crypto) })).toMatch(/^[A-Za-z0-9_-]{8,100}$/)
  })
})
