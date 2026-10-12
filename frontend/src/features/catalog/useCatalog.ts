// Catálogos de solo lectura: GET /warehouses y GET /products (S-86).
// Disponibles para todos los roles. Son pocos registros: se cargan completos
// (hasta MAX_PAGES páginas de 100) y se guardan 5 minutos en caché.
import { useQuery } from '@tanstack/react-query'
import { apiRequest } from '../../api/client'
import type { Paginated, Product, WarehouseSummary } from '../../api/types'

const MAX_PAGES = 10

export interface Catalog {
  warehouses: WarehouseSummary[]
  products: Product[]
}

async function fetchAll<T>(path: string): Promise<T[]> {
  const rows: T[] = []
  for (let page = 1; page <= MAX_PAGES; page++) {
    const result = await apiRequest<Paginated<T>>(path, { query: { per_page: 100, page } })
    rows.push(...result.data)
    if (page >= result.meta.last_page) break
  }
  return rows
}

async function fetchCatalog(): Promise<Catalog> {
  const [warehouses, products] = await Promise.all([fetchAll<WarehouseSummary>('/warehouses'), fetchAll<Product>('/products')])
  return { warehouses, products }
}

export function useCatalog() {
  return useQuery({ queryKey: ['catalog'], queryFn: fetchCatalog, staleTime: 5 * 60_000 })
}

// Búsqueda de productos por nombre o código (para prescribir).
export function useProductSearch(q: string) {
  const term = q.trim()
  return useQuery({
    queryKey: ['products', 'search', term],
    queryFn: () => apiRequest<Paginated<Product>>('/products', { query: { q: term, per_page: 20 } }).then((r) => r.data),
    enabled: term.length >= 2,
    staleTime: 60_000,
  })
}
