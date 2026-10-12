// Bodegas y productos para los selectores.
//
// La API no tiene endpoints de catálogo (GET /warehouses, GET /products):
// se derivan de GET /stocks?include_empty=true (S-80). Limitación: un
// producto sin ninguna existencia registrada en ninguna bodega no aparece.
// Solo pueden usarlo los roles con acceso a /stocks (farmacia y auditor).
import { useQuery } from '@tanstack/react-query'
import { apiRequest } from '../../api/client'
import type { Paginated, ProductSummary, Stock, WarehouseSummary } from '../../api/types'

const MAX_PAGES = 10

export interface Catalog {
  warehouses: WarehouseSummary[]
  products: ProductSummary[]
}

async function fetchCatalog(): Promise<Catalog> {
  const warehouses = new Map<number, WarehouseSummary>()
  const products = new Map<number, ProductSummary>()
  for (let page = 1; page <= MAX_PAGES; page++) {
    const result = await apiRequest<Paginated<Stock>>('/stocks', { query: { include_empty: 1, per_page: 100, page } })
    for (const stock of result.data) {
      warehouses.set(stock.warehouse.id, stock.warehouse)
      products.set(stock.product.id, stock.product)
    }
    if (page >= result.meta.last_page) break
  }

  const byName = (a: { name: string }, b: { name: string }) => a.name.localeCompare(b.name, 'es')
  return { warehouses: [...warehouses.values()].sort(byName), products: [...products.values()].sort(byName) }
}

export function useCatalog() {
  return useQuery({ queryKey: ['catalog'], queryFn: fetchCatalog, staleTime: 5 * 60_000 })
}
