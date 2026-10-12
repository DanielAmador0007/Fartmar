// Tipos de la API v1 (fuente de verdad: docs/openapi.yaml 0.4.0).
// Solo se tipan los campos que usa la interfaz.

export type Role = 'auxiliar_farmacia' | 'regente_farmacia' | 'medico' | 'auditor' | 'admin'

export interface User {
  id: number
  name: string
  email: string
  role: Role
  is_active: boolean
  must_change_password: boolean
}

export interface UserSummary {
  id: number
  name: string
  role: string
}

export interface LoginResponse {
  token: string
  token_type: string
  expires_at: string | null
  user: User
}

export interface DataEnvelope<T> {
  data: T
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface Paginated<T> {
  data: T[]
  meta: PaginationMeta
}

export interface WarehouseSummary {
  id: number
  code: string
  name: string
}

export interface ProductSummary {
  id: number
  code: string
  name: string
  is_controlled?: boolean
}

export interface LotSummary {
  id: number
  lot_number: string
  expires_at: string
}

// --- Pacientes y prescripciones ---

export type DocumentType = 'CC' | 'TI' | 'CE' | 'RC' | 'PA'

// Para el auditor llega enmascarado (masked=true) y con birth_date/phone en null.
export interface Patient {
  id: number
  document_type: DocumentType
  document_number: string
  first_name: string
  last_name: string
  birth_date: string | null
  phone: string | null
  masked: boolean
}

export interface PrescriptionItem {
  id: number
  product: ProductSummary & { is_controlled: boolean }
  quantity_prescribed: number
  quantity_dispensed: number
  quantity_reserved: number
  quantity_available: number
  instructions: string | null
}

export interface Prescription {
  id: number
  number: string
  patient_id: number
  status: 'ACTIVA' | 'COMPLETADA' | 'ANULADA'
  is_current: boolean
  issued_at: string
  valid_until: string
  prescriber: UserSummary | null
  items: PrescriptionItem[]
}

// --- Dispensación ---

export interface LotAllocation {
  lot_id: number
  lot_number: string
  expires_at: string
  quantity: number
}

export interface FefoPreview {
  warehouse_id: number
  product: ProductSummary & { is_controlled: boolean }
  quantity: number
  requires_authorization: boolean
  lots: LotAllocation[]
}

export type DispensationStatus = 'PENDIENTE_AUTORIZACION' | 'COMPLETADA' | 'RECHAZADA'

export interface DispensationRequest {
  patient_id: number
  prescription_id: number
  warehouse_id: number
  items: { prescription_item_id: number; quantity: number }[]
}

export interface Dispensation {
  id: number
  status: DispensationStatus
  requires_authorization: boolean
  patient_id: number
  prescription: { id: number; number: string }
  warehouse: WarehouseSummary
  items: {
    id: number
    prescription_item_id: number
    product: ProductSummary & { is_controlled: boolean }
    quantity: number
    lots: LotAllocation[]
  }[]
  created_by: UserSummary
  authorized_by: UserSummary | null
  authorized_at: string | null
  rejected_by: UserSummary | null
  rejected_at: string | null
  rejection_reason: string | null
  created_at: string
}

// --- Inventario ---

export interface Stock {
  id: number
  warehouse: WarehouseSummary
  product: ProductSummary & { is_controlled: boolean }
  lot: LotSummary & { is_expired: boolean }
  quantity: number
}

export interface InTransitStock {
  transfer_id: number
  transfer_item_lot_id: number
  dispatched_at: string
  origin_warehouse: WarehouseSummary
  destination_warehouse: WarehouseSummary
  product: ProductSummary
  lot: LotSummary & { is_expired: boolean }
  quantity: number
}

export interface Alerts {
  today: string
  expiry_days: number
  limit: number
  expiring_lots_total: number
  low_stock_total: number
  expiring_lots: {
    stock_id: number
    warehouse: WarehouseSummary
    product: ProductSummary
    lot: LotSummary
    quantity: number
    days_to_expire: number
    is_expired: boolean
  }[]
  low_stock: {
    warehouse: WarehouseSummary
    product: ProductSummary
    min_quantity: number
    available: number
    shortfall: number
  }[]
}

export type KardexType =
  | 'ENTRADA'
  | 'SALIDA_DISPENSACION'
  | 'SALIDA_TRASLADO'
  | 'ENTRADA_TRASLADO'
  | 'AJUSTE'

export type ReferenceType = 'dispensation' | 'transfer' | 'transfer_discrepancy'

export interface KardexMovement {
  id: number
  created_at: string
  type: KardexType
  direction: -1 | 1
  quantity: number
  signed_quantity: number
  balance_after: number
  warehouse: WarehouseSummary
  product: ProductSummary
  lot: LotSummary
  reason: string | null
  reference: { type: ReferenceType; id: number } | null
  user: UserSummary | null
}

export interface AdjustmentRequest {
  warehouse_id: number
  lot_id: number
  quantity: number
  reason: string
}

// --- Traslados ---

export type TransferStatus =
  | 'BORRADOR'
  | 'SOLICITADO'
  | 'APROBADO'
  | 'EN_TRANSITO'
  | 'RECIBIDO'
  | 'RECIBIDO_PARCIAL'
  | 'ANULADO'

export type TransferAction = 'submit' | 'approve' | 'dispatch' | 'receive' | 'cancel'

export interface TransferItemLot {
  transfer_item_lot_id: number
  lot_id: number
  lot_number: string
  expires_at: string
  quantity_dispatched: number
  quantity_received: number | null
  discrepancy: {
    id: number
    status: 'PENDIENTE' | 'RESUELTA'
    quantity_missing: number
    resolution_action: 'REINTEGRAR_ORIGEN' | 'PERDIDA' | null
  } | null
}

export interface Transfer {
  id: number
  status: TransferStatus
  origin_warehouse: WarehouseSummary
  destination_warehouse: WarehouseSummary
  notes: string | null
  items_count?: number
  items?: {
    id: number
    product: ProductSummary
    quantity_requested: number
    lots: TransferItemLot[]
  }[]
  requested_by: UserSummary | null
  requested_at: string | null
  approved_by: UserSummary | null
  dispatched_by: UserSummary | null
  received_by: UserSummary | null
  cancelled_by: UserSummary | null
  cancellation_reason: string | null
  created_at: string
  allowed_actions: TransferAction[]
}

export interface TransferRequest {
  origin_warehouse_id: number
  destination_warehouse_id: number
  notes: string | null
  items: { product_id: number; quantity: number }[]
}

export type DiscrepancyAction = 'REINTEGRAR_ORIGEN' | 'PERDIDA'

export interface TransferDiscrepancy {
  id: number
  status: 'PENDIENTE' | 'RESUELTA'
  transfer_id: number
  transfer_status: TransferStatus
  origin_warehouse_id: number
  destination_warehouse_id: number
  transfer_item_lot_id: number
  product: ProductSummary
  lot: LotSummary
  quantity_dispatched: number
  quantity_received: number
  quantity_missing: number
  resolution_action: DiscrepancyAction | null
  resolution: string | null
  resolved_by: UserSummary | null
  resolved_at: string | null
  created_at: string
}
