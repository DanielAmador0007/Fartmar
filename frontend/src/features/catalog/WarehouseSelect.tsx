import type { ProductSummary, WarehouseSummary } from '../../api/types'

interface SelectProps<T> {
  label: string
  value: number | null
  options: T[]
  onChange: (id: number | null) => void
  allowEmpty?: string // texto de la opción vacía ("Todas", "Seleccione…")
  required?: boolean
}

export function WarehouseSelect({ label, value, options, onChange, allowEmpty, required }: SelectProps<WarehouseSummary>) {
  return (
    <label>
      {label}
      <select required={required} value={value ?? ''} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
        {allowEmpty !== undefined && <option value="">{allowEmpty}</option>}
        {options.map((w) => (
          <option key={w.id} value={w.id}>
            {w.name} ({w.code})
          </option>
        ))}
      </select>
    </label>
  )
}

export function ProductSelect({ label, value, options, onChange, allowEmpty, required }: SelectProps<ProductSummary>) {
  return (
    <label>
      {label}
      <select required={required} value={value ?? ''} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
        {allowEmpty !== undefined && <option value="">{allowEmpty}</option>}
        {options.map((p) => (
          <option key={p.id} value={p.id}>
            {p.name} ({p.code})
          </option>
        ))}
      </select>
    </label>
  )
}
