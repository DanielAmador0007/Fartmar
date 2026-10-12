import { useMutation, useQuery } from '@tanstack/react-query'
import { apiRequest } from '../../api/client'
import type { DataEnvelope, DocumentType, Patient, Prescription } from '../../api/types'

export const DOCUMENT_TYPES: DocumentType[] = ['CC', 'TI', 'CE', 'RC', 'PA']

export const patientsApi = {
  // POST para que el documento no quede en URLs ni logs (S-69). 0 o 1 resultado.
  search: (document_type: DocumentType, document_number: string) =>
    apiRequest<DataEnvelope<Patient[]>>('/patients/search', {
      method: 'POST',
      body: { document_type, document_number },
    }).then((r) => r.data[0] ?? null),
  prescriptions: (patientId: number) =>
    apiRequest<DataEnvelope<Prescription[]>>(`/patients/${patientId}/prescriptions`).then((r) => r.data),
}

// Mutación (no consulta) para no dejar el documento como clave de caché.
export function usePatientSearch() {
  return useMutation({
    mutationFn: ({ documentType, documentNumber }: { documentType: DocumentType; documentNumber: string }) =>
      patientsApi.search(documentType, documentNumber),
  })
}

export function usePrescriptions(patientId: number | null) {
  return useQuery({
    queryKey: ['patients', patientId, 'prescriptions'],
    queryFn: () => patientsApi.prescriptions(patientId as number),
    enabled: patientId !== null,
    staleTime: 0,
  })
}
