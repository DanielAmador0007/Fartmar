import { useState, type FormEvent } from 'react'
import type { DocumentType, Patient } from '../../api/types'
import { ErrorAlert } from '../../components/ui'
import { orDash } from '../../lib/format'
import { DOCUMENT_TYPES, usePatientSearch } from './patientsApi'

// Buscador por documento: foco automático y Enter para buscar.
export function PatientSearchForm({ onResult }: { onResult: (patient: Patient | null) => void }) {
  const [documentType, setDocumentType] = useState<DocumentType>('CC')
  const [documentNumber, setDocumentNumber] = useState('')
  const [searched, setSearched] = useState(false)
  const search = usePatientSearch()

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (search.isPending || documentNumber.trim().length < 3) return
    search.mutate(
      { documentType, documentNumber: documentNumber.trim() },
      {
        onSuccess: (patient) => {
          setSearched(true)
          onResult(patient)
        },
      },
    )
  }

  return (
    <form className="card inline-form" onSubmit={handleSubmit} aria-label="Buscar paciente">
      <label>
        Tipo
        <select value={documentType} onChange={(e) => setDocumentType(e.target.value as DocumentType)}>
          {DOCUMENT_TYPES.map((type) => (
            <option key={type}>{type}</option>
          ))}
        </select>
      </label>
      <label className="grow">
        Documento del paciente
        <input
          autoFocus
          autoComplete="off"
          inputMode="numeric"
          minLength={3}
          maxLength={30}
          value={documentNumber}
          onChange={(e) => setDocumentNumber(e.target.value)}
          placeholder="Número y Enter"
        />
      </label>
      <button type="submit" className="primary" disabled={search.isPending}>
        {search.isPending ? 'Buscando…' : 'Buscar'}
      </button>
      <div className="full">
        <ErrorAlert error={search.error} action="consultar pacientes" />
        {searched && !search.isPending && search.data === null && <p className="empty">No se encontró un paciente con ese documento.</p>}
      </div>
    </form>
  )
}

// Datos del paciente tal como llegan: para el auditor vienen enmascarados.
export function PatientCard({ patient }: { patient: Patient }) {
  return (
    <section className="card patient-card" aria-label="Paciente">
      <h2>
        {patient.first_name} {patient.last_name}
      </h2>
      <p>
        {patient.document_type} {patient.document_number}
        {' · '}Nacimiento: {orDash(patient.birth_date)}
        {' · '}Teléfono: {orDash(patient.phone)}
      </p>
      {patient.masked && <p className="muted">Datos enmascarados según tu rol.</p>}
    </section>
  )
}
