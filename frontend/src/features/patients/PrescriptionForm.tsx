import { useState, type FormEvent } from 'react'
import type { Product } from '../../api/types'
import { ErrorAlert, Loading, SuccessAlert } from '../../components/ui'
import { useDebouncedValue } from '../../lib/useDebouncedValue'
import { useSubmitGuard } from '../../lib/useSubmitGuard'
import { useProductSearch } from '../catalog/useCatalog'
import { useCreatePrescription } from './patientsApi'
import { buildPrescription, MAX_LINES, validUntilRange, type PrescriptionLine } from './prescriptionLines'

// Nueva prescripción para el paciente seleccionado (solo médico).
export function PrescriptionForm({ patientId }: { patientId: number }) {
  const range = validUntilRange()
  const [validUntil, setValidUntil] = useState(range.suggested)
  const [lines, setLines] = useState<PrescriptionLine[]>([])
  const [query, setQuery] = useState('')
  const [showProblems, setShowProblems] = useState(false)
  const search = useProductSearch(useDebouncedValue(query))
  const create = useCreatePrescription()
  const guard = useSubmitGuard()

  const { request, problems } = buildPrescription(patientId, validUntil, lines)
  const chosen = new Set(lines.map((l) => l.product.id))

  function add(product: Product) {
    if (chosen.has(product.id) || lines.length >= MAX_LINES) return
    setLines((current) => [...current, { product, quantity: '', instructions: '' }])
    setQuery('')
  }

  function update(index: number, patch: Partial<PrescriptionLine>) {
    setLines((current) => current.map((l, i) => (i === index ? { ...l, ...patch } : l)))
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setShowProblems(true)
    if (!request) return
    void guard(() =>
      create.mutateAsync(request).then(() => {
        setLines([])
        setShowProblems(false)
      }),
    )
  }

  return (
    <form className="card" onSubmit={handleSubmit} aria-label="Nueva prescripción">
      <h2>Nueva prescripción</h2>
      <label>
        Válida hasta
        <input type="date" required min={range.min} max={range.max} value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />
      </label>

      <label>
        Buscar medicamento (nombre o código)
        <input value={query} maxLength={100} onChange={(e) => setQuery(e.target.value)} placeholder="Escribe al menos 2 letras" />
      </label>
      {search.isFetching && <Loading label="Buscando…" />}
      <ErrorAlert error={search.error} action="buscar medicamentos" />
      {query.trim().length >= 2 && search.data?.length === 0 && <p className="empty">Sin resultados.</p>}
      {search.data && search.data.length > 0 && query.trim().length >= 2 && (
        <ul className="pick-list" aria-label="Resultados de medicamentos">
          {search.data.map((p) => (
            <li key={p.id}>
              <span>
                {p.name} ({p.code}) · {p.presentation} {p.is_controlled && <span className="badge badge-red">Control especial</span>}
              </span>
              <button type="button" disabled={chosen.has(p.id)} onClick={() => add(p)}>
                {chosen.has(p.id) ? 'Agregado' : `Agregar ${p.name}`}
              </button>
            </li>
          ))}
        </ul>
      )}

      {lines.length > 0 && (
        <table>
          <thead>
            <tr>
              <th>Medicamento</th>
              <th>Cantidad</th>
              <th>Indicaciones</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {lines.map((line, index) => (
              <tr key={line.product.id}>
                <td>
                  {line.product.name} <span className="muted">({line.product.unit})</span>
                </td>
                <td>
                  <input
                    type="number"
                    min={1}
                    max={10000}
                    aria-label={`Cantidad de ${line.product.name}`}
                    value={line.quantity}
                    onChange={(e) => update(index, { quantity: e.target.value })}
                  />
                </td>
                <td>
                  <input
                    aria-label={`Indicaciones de ${line.product.name}`}
                    maxLength={255}
                    value={line.instructions}
                    onChange={(e) => update(index, { instructions: e.target.value })}
                  />
                </td>
                <td>
                  <button type="button" onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>
                    Quitar
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {showProblems && problems.length > 0 && (
        <div role="alert" className="alert alert-error">
          {problems.map((p) => (
            <p key={p}>{p}</p>
          ))}
        </div>
      )}
      <ErrorAlert error={create.error} action="crear prescripciones" />
      {create.isSuccess && <SuccessAlert>Prescripción {create.data.number} creada.</SuccessAlert>}
      <button type="submit" className="primary" disabled={create.isPending}>
        {create.isPending ? 'Guardando…' : 'Crear prescripción'}
      </button>
    </form>
  )
}
