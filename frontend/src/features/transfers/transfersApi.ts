import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest } from '../../api/client'
import type {
  DataEnvelope,
  DiscrepancyAction,
  Paginated,
  Transfer,
  TransferDiscrepancy,
  TransferRequest,
  TransferStatus,
} from '../../api/types'

export const transfersApi = {
  list: (params: { status?: TransferStatus | ''; warehouse_id?: number | null; page: number }) =>
    apiRequest<Paginated<Transfer>>('/transfers', { query: { ...params, per_page: 25 } }),
  get: (id: number) => apiRequest<DataEnvelope<Transfer>>(`/transfers/${id}`).then((r) => r.data),
  create: (body: TransferRequest) =>
    apiRequest<DataEnvelope<Transfer>>('/transfers', { method: 'POST', body }).then((r) => r.data),
  action: (id: number, action: 'submit' | 'approve' | 'dispatch') =>
    apiRequest<DataEnvelope<Transfer>>(`/transfers/${id}/${action}`, { method: 'POST' }).then((r) => r.data),
  cancel: (id: number, reason: string) =>
    apiRequest<DataEnvelope<Transfer>>(`/transfers/${id}/cancel`, { method: 'POST', body: { reason } }).then((r) => r.data),
  receive: (id: number, lots: { transfer_item_lot_id: number; quantity_received: number }[]) =>
    apiRequest<DataEnvelope<Transfer>>(`/transfers/${id}/receive`, { method: 'POST', body: { lots } }).then((r) => r.data),
  discrepancies: (params: { status?: 'PENDIENTE' | 'RESUELTA'; transfer_id?: number; page?: number }) =>
    apiRequest<Paginated<TransferDiscrepancy>>('/transfer-discrepancies', { query: { ...params, per_page: 50 } }),
  resolve: (id: number, action: DiscrepancyAction, resolution: string) =>
    apiRequest<DataEnvelope<TransferDiscrepancy>>(`/transfer-discrepancies/${id}/resolve`, {
      method: 'POST',
      body: { action, resolution },
    }).then((r) => r.data),
}

export function useTransfers(params: { status: TransferStatus | ''; warehouse_id: number | null; page: number }) {
  return useQuery({ queryKey: ['transfers', 'list', params], queryFn: () => transfersApi.list(params) })
}

export function useTransfer(id: number | null) {
  return useQuery({
    queryKey: ['transfers', 'detail', id],
    queryFn: () => transfersApi.get(id as number),
    enabled: id !== null,
  })
}

export function useCreateTransfer() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: transfersApi.create,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['transfers', 'list'] }),
  })
}

export type TransferMutationInput =
  | { kind: 'submit' | 'approve' | 'dispatch' }
  | { kind: 'cancel'; reason: string }
  | { kind: 'receive'; lots: { transfer_item_lot_id: number; quantity_received: number }[] }

// Una sola mutación para las acciones del traslado; tras cualquiera se
// refrescan detalle, listas, existencias y discrepancias.
export function useTransferAction(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: TransferMutationInput) => {
      if (input.kind === 'cancel') return transfersApi.cancel(id, input.reason)
      if (input.kind === 'receive') return transfersApi.receive(id, input.lots)
      return transfersApi.action(id, input.kind)
    },
    onSuccess: (transfer) => {
      queryClient.setQueryData(['transfers', 'detail', id], transfer)
      void queryClient.invalidateQueries({ queryKey: ['transfers', 'list'] })
      void queryClient.invalidateQueries({ queryKey: ['discrepancies'] })
      void queryClient.invalidateQueries({ queryKey: ['stocks'] })
    },
    // Un 409 (otro usuario lo cambió) deja la pantalla vieja: se recarga el detalle.
    onError: () => queryClient.invalidateQueries({ queryKey: ['transfers', 'detail', id] }),
  })
}

export function useDiscrepancies(params: { status?: 'PENDIENTE' | 'RESUELTA'; transfer_id?: number }) {
  return useQuery({ queryKey: ['discrepancies', params], queryFn: () => transfersApi.discrepancies(params) })
}

export function useResolveDiscrepancy() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, action, resolution }: { id: number; action: DiscrepancyAction; resolution: string }) =>
      transfersApi.resolve(id, action, resolution),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['discrepancies'] })
      void queryClient.invalidateQueries({ queryKey: ['transfers'] })
      void queryClient.invalidateQueries({ queryKey: ['stocks'] })
    },
  })
}
