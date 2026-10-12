import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest } from '../../api/client'
import type { AdjustmentRequest, Alerts, DataEnvelope, InTransitStock, KardexMovement, Paginated, Stock } from '../../api/types'

export interface StockFilters {
  warehouse_id: number | null
  product_id: number | null
  page: number
}

export const inventoryApi = {
  stocks: (f: StockFilters) => apiRequest<Paginated<Stock>>('/stocks', { query: { ...f, per_page: 50 } }),
  inTransit: (warehouseId: number | null) =>
    apiRequest<Paginated<InTransitStock>>('/stocks/in-transit', { query: { warehouse_id: warehouseId, per_page: 100 } }),
  alerts: (warehouseId: number | null) =>
    apiRequest<DataEnvelope<Alerts>>('/alerts', { query: { warehouse_id: warehouseId } }).then((r) => r.data),
  adjust: (body: AdjustmentRequest) =>
    apiRequest<DataEnvelope<KardexMovement>>('/inventory-adjustments', { method: 'POST', body }).then((r) => r.data),
}

export function useStocks(filters: StockFilters) {
  return useQuery({ queryKey: ['stocks', 'list', filters], queryFn: () => inventoryApi.stocks(filters) })
}

export function useInTransit(warehouseId: number | null) {
  return useQuery({ queryKey: ['stocks', 'in-transit', warehouseId], queryFn: () => inventoryApi.inTransit(warehouseId) })
}

export function useAlerts(warehouseId: number | null) {
  return useQuery({ queryKey: ['stocks', 'alerts', warehouseId], queryFn: () => inventoryApi.alerts(warehouseId) })
}

// Ajustes no son idempotentes en la API (S-61): el formulario bloquea el doble clic.
export function useAdjustInventory() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: inventoryApi.adjust,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['stocks'] })
      void queryClient.invalidateQueries({ queryKey: ['kardex'] })
    },
  })
}
