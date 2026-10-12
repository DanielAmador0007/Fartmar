import { QueryClient } from '@tanstack/react-query'
import { ApiError } from './api/client'

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // Operaciones de inventario: preferimos datos frescos.
        staleTime: 10_000,
        // Solo se reintenta un fallo de red o 5xx; un 4xx no cambia al repetir.
        retry: (failureCount, error) =>
          failureCount < 1 && (!(error instanceof ApiError) || error.status === 0 || error.status >= 500),
        refetchOnWindowFocus: false,
      },
      // Nunca reintentar mutaciones solas: el usuario decide reintentar
      // (la dispensación reutiliza su Idempotency-Key, ver features/dispensing).
      mutations: { retry: false },
    },
  })
}
