// Datos SINTÉTICOS para pruebas.
import type { Dispensation, FefoPreview, Patient, Prescription, Stock, Transfer } from '../api/types'

export const warehouse = { id: 1, code: 'FAR-CEN', name: 'Farmacia Central' }
export const warehouse2 = { id: 2, code: 'FAR-URG', name: 'Farmacia Urgencias' }
export const acetaminofen = { id: 1, code: 'ACE500', name: 'Acetaminofén 500 mg', is_controlled: false }
export const morfina = { id: 6, code: 'MOR10', name: 'Morfina 10 mg/ml', is_controlled: true }

export const patient: Patient = {
  id: 1,
  document_type: 'CC',
  document_number: '9900000001',
  first_name: 'Ana',
  last_name: 'Prueba',
  birth_date: '1980-01-01',
  phone: '3000000000',
  masked: false,
}

export const maskedPatient: Patient = {
  ...patient,
  document_number: '******0001',
  first_name: 'A***',
  last_name: 'P***',
  birth_date: null,
  phone: null,
  masked: true,
}

export function prescription(overrides: Partial<Prescription> = {}): Prescription {
  return {
    id: 1,
    number: 'RX-DEMO-0001',
    patient_id: 1,
    status: 'ACTIVA',
    is_current: true,
    issued_at: '2026-10-01T10:00:00Z',
    valid_until: '2026-12-31',
    prescriber: { id: 5, name: 'Médico Demo', role: 'medico' },
    items: [
      {
        id: 11,
        product: acetaminofen,
        quantity_prescribed: 20,
        quantity_dispensed: 0,
        quantity_reserved: 0,
        quantity_available: 20,
        instructions: 'Cada 8 horas',
      },
    ],
    ...overrides,
  }
}

export function stock(id: number, overrides: Partial<Stock> = {}): Stock {
  return {
    id,
    warehouse,
    product: acetaminofen,
    lot: { id, lot_number: `L-${id}`, expires_at: '2027-06-01', is_expired: false },
    quantity: 50,
    ...overrides,
  }
}

export const catalogStocks = { data: [stock(1), stock(2, { warehouse: warehouse2 })], meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 } }

export const fefoPreview: FefoPreview = {
  warehouse_id: 1,
  product: acetaminofen,
  quantity: 20,
  requires_authorization: false,
  lots: [
    { lot_id: 2, lot_number: 'L-ACE-2402', expires_at: '2026-10-31', quantity: 15 },
    { lot_id: 3, lot_number: 'L-ACE-2403', expires_at: '2027-08-04', quantity: 5 },
  ],
}

export function dispensation(overrides: Partial<Dispensation> = {}): Dispensation {
  return {
    id: 77,
    status: 'COMPLETADA',
    requires_authorization: false,
    patient_id: 1,
    prescription: { id: 1, number: 'RX-DEMO-0001' },
    warehouse,
    items: [{ id: 1, prescription_item_id: 11, product: acetaminofen, quantity: 20, lots: fefoPreview.lots }],
    created_by: { id: 10, name: 'Auxiliar Demo', role: 'auxiliar_farmacia' },
    authorized_by: null,
    authorized_at: null,
    rejected_by: null,
    rejected_at: null,
    rejection_reason: null,
    created_at: '2026-10-11T15:00:00Z',
    ...overrides,
  }
}

export function transfer(overrides: Partial<Transfer> = {}): Transfer {
  return {
    id: 5,
    status: 'SOLICITADO',
    origin_warehouse: warehouse,
    destination_warehouse: warehouse2,
    notes: 'Reposición semanal',
    items: [{ id: 1, product: acetaminofen, quantity_requested: 10, lots: [] }],
    requested_by: { id: 99, name: 'Otro Usuario', role: 'auxiliar_farmacia' },
    requested_at: '2026-10-11T12:00:00Z',
    approved_by: null,
    dispatched_by: null,
    received_by: null,
    cancelled_by: null,
    cancellation_reason: null,
    created_at: '2026-10-11T11:00:00Z',
    allowed_actions: [],
    ...overrides,
  }
}
