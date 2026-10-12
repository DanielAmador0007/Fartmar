import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError, apiRequest } from '../../api/client'
import type {
  DataEnvelope,
  Dispensation,
  DispensationRequest,
  DispensationStatus,
  DispensationSummary,
  FefoPreview,
  Paginated,
} from '../../api/types'
import { createIdempotencyTracker } from './idempotency'

export const dispensingApi = {
  create: (payload: DispensationRequest, idempotencyKey: string) =>
    apiRequest<DataEnvelope<Dispensation>>('/dispensations', {
      method: 'POST',
      body: payload,
      headers: { 'Idempotency-Key': idempotencyKey },
    }).then((r) => r.data),
  list: (f: { status: DispensationStatus | ''; warehouse_id: number | null; page: number }) =>
    apiRequest<Paginated<DispensationSummary>>('/dispensations', { query: { ...f, per_page: 25 } }),
  get: (id: number) => apiRequest<DataEnvelope<Dispensation>>(`/dispensations/${id}`).then((r) => r.data),
  authorize: (id: number) =>
    apiRequest<DataEnvelope<Dispensation>>(`/dispensations/${id}/authorize`, { method: 'POST' }).then((r) => r.data),
  reject: (id: number, reason: string) =>
    apiRequest<DataEnvelope<Dispensation>>(`/dispensations/${id}/reject`, { method: 'POST', body: { reason } }).then(
      (r) => r.data,
    ),
  preview: (warehouseId: number, productId: number, quantity: number) =>
    apiRequest<DataEnvelope<FefoPreview>>('/dispensations/preview', {
      query: { warehouse_id: warehouseId, product_id: productId, quantity },
    }).then((r) => r.data),
}

// Crear dispensación con Idempotency-Key estable por intento (ver idempotency.ts).
export function useCreateDispensation() {
  const queryClient = useQueryClient()
  const [tracker] = useState(() => createIdempotencyTracker())

  return useMutation({
    mutationFn: (payload: DispensationRequest) => dispensingApi.create(payload, tracker.keyFor(payload)),
    onSuccess: (dispensation) => {
      tracker.reset()
      // Cambiaron entregado/reservado de la prescripción y las existencias.
      void queryClient.invalidateQueries({ queryKey: ['patients', dispensation.patient_id, 'prescriptions'] })
      void queryClient.invalidateQueries({ queryKey: ['stocks'] })
    },
    onError: (error) => {
      if (error instanceof ApiError && error.code === 'IDEMPOTENCIA_CONFLICTO') tracker.reset()
    },
  })
}

export interface PreviewLine {
  productId: number
  quantity: number
}

// Vista previa FEFO calculada por el backend (GET /dispensations/preview),
// una consulta por línea. El cliente no implementa FEFO.
export function useFefoPreviews(warehouseId: number | null, lines: PreviewLine[]) {
  return useQueries({
    queries: lines.map((line) => ({
      queryKey: ['fefo-preview', warehouseId, line.productId, line.quantity],
      queryFn: () => dispensingApi.preview(warehouseId as number, line.productId, line.quantity),
      enabled: warehouseId !== null,
      staleTime: 0,
      retry: false,
    })),
  })
}

export function useDispensation(id: number | null) {
  return useQuery({
    queryKey: ['dispensations', id],
    queryFn: () => dispensingApi.get(id as number),
    enabled: id !== null,
    retry: false,
  })
}

export interface DispensationFilters {
  status: DispensationStatus | ''
  warehouse_id: number | null
  page: number
}

// Bandeja (S-87): resumen sin datos del paciente.
export function useDispensations(filters: DispensationFilters) {
  return useQuery({
    queryKey: ['dispensations', 'list', filters],
    queryFn: () => dispensingApi.list(filters),
  })
}

// Tras autorizar o rechazar: detalle actualizado y bandeja/existencias recargadas.
function useAfterDecision() {
  const queryClient = useQueryClient()
  return (d: Dispensation) => {
    queryClient.setQueryData(['dispensations', d.id], d)
    void queryClient.invalidateQueries({ queryKey: ['dispensations', 'list'] })
    void queryClient.invalidateQueries({ queryKey: ['stocks'] })
  }
}

export function useAuthorizeDispensation() {
  const onSuccess = useAfterDecision()
  return useMutation({ mutationFn: (id: number) => dispensingApi.authorize(id), onSuccess })
}

export function useRejectDispensation() {
  const onSuccess = useAfterDecision()
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => dispensingApi.reject(id, reason),
    onSuccess,
  })
}
