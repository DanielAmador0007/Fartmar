import { useCallback, useRef } from 'react'

// Anti doble clic para mutaciones. Además de deshabilitar el botón con
// `isPending`, bloquea en el mismo instante del primer clic: `isPending` se
// actualiza en el siguiente render y un doble clic muy rápido podría colarse.
// El error queda en `mutation.error`, por eso aquí se ignora.
export function useSubmitGuard() {
  const busy = useRef(false)
  return useCallback(async (run: () => Promise<unknown>): Promise<void> => {
    if (busy.current) return
    busy.current = true
    try {
      await run()
    } catch {
      // visible en la interfaz vía mutation.error
    } finally {
      busy.current = false
    }
  }, [])
}
