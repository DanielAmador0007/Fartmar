import { useState } from 'react'
import type { Patient } from '../../api/types'
import { Empty, ErrorAlert, Loading } from '../../components/ui'
import { usePrescriptions } from './patientsApi'
import { PatientCard, PatientSearchForm } from './PatientSearch'
import { PrescriptionHeader, PrescriptionLines } from './PrescriptionTable'

// Consulta de pacientes y sus prescripciones (solo lectura).
// El paciente vive solo en el estado de esta pantalla (memoria), nunca en storage.
export function PatientsPage() {
  const [patient, setPatient] = useState<Patient | null>(null)
  const prescriptions = usePrescriptions(patient?.id ?? null)

  return (
    <>
      <h1>Pacientes</h1>
      <PatientSearchForm onResult={setPatient} />
      {patient && (
        <>
          <PatientCard patient={patient} />
          <section className="card">
            <h2>Prescripciones</h2>
            {prescriptions.isPending && <Loading />}
            <ErrorAlert error={prescriptions.error} action="ver prescripciones" />
            {prescriptions.data?.length === 0 && <Empty>El paciente no tiene prescripciones.</Empty>}
            {prescriptions.data?.map((p) => (
              <div key={p.id} className="block">
                <PrescriptionHeader prescription={p} />
                <PrescriptionLines prescription={p} />
              </div>
            ))}
          </section>
        </>
      )}
    </>
  )
}
