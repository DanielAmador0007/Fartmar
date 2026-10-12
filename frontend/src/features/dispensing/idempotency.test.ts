import { describe, expect, it } from 'vitest'
import { createIdempotencyTracker } from './idempotency'

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
