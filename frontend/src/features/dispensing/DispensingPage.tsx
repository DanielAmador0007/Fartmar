import { useState } from 'react'
import { Link } from 'react-router'
import type { Dispensation, Patient } from '../../api/types'
import { useCan } from '../../auth/AuthContext'
import { Empty, ErrorAlert, Loading } from '../../components/ui'
import { usePrescriptions } from '../patients/patientsApi'
import { PatientCard, PatientSearchForm } from '../patients/PatientSearch'
import { PrescriptionHeader } from '../patients/PrescriptionTable'
import { DispensationSummary } from './DispensationView'
import { DispenseForm } from './DispenseForm'

// Flujo: paciente → prescripción vigente → cantidades → vista previa FEFO → confirmar.
export function DispensingPage() {
  const [patient, setPatient] = useState<Patient | null>(null)
  const [prescriptionId, setPrescriptionId] = useState<number | null>(null)
  const [result, setResult] = useState<Dispensation | null>(null)
  const prescriptions = usePrescriptions(patient?.id ?? null)
  const canAuthorize = useCan('authorizeControlled')

  const current = prescriptions.data?.filter((p) => p.is_current) ?? []
  const selected = current.find((p) => p.id === prescriptionId) ?? null

  function handlePatient(found: Patient | null) {
    setPatient(found)
    setPrescriptionId(null)
    setResult(null)
  }

  return (
    <>
      <h1>Dispensación</h1>
      <PatientSearchForm onResult={handlePatient} />

      {patient && <PatientCard patient={patient} />}

      {result && (
        <>
          <DispensationSummary dispensation={result} />
          <div className="actions">
            <button type="button" className="primary" onClick={() => setResult(null)}>
              Nueva dispensación
            </button>
            {result.status === 'PENDIENTE_AUTORIZACION' && canAuthorize && (
              <Link to={`/autorizaciones?id=${result.id}`}>Ir a autorizaciones</Link>
            )}
          </div>
        </>
      )}

      {patient && !result && (
        <section className="card" aria-label="Prescripciones vigentes">
          <h2>Prescripciones vigentes</h2>
          {prescriptions.isPending && <Loading />}
          <ErrorAlert error={prescriptions.error} action="ver prescripciones" />
          {prescriptions.isSuccess && current.length === 0 && <Empty>El paciente no tiene prescripciones vigentes.</Empty>}
          {current.map((p) => (
            <div key={p.id} className="row-choice">
              <PrescriptionHeader prescription={p} />
              <button type="button" onClick={() => setPrescriptionId(p.id)} disabled={p.id === prescriptionId}>
                {p.id === prescriptionId ? 'Seleccionada' : 'Elegir'}
              </button>
            </div>
          ))}
        </section>
      )}

      {patient && selected && !result && (
        <DispenseForm key={selected.id} patientId={patient.id} prescription={selected} onDone={setResult} />
      )}
    </>
  )
}
