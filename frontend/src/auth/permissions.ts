// Qué secciones y acciones MUESTRA la interfaz según el rol (CLAUDE.md §5).
// Es solo para no ofrecer botones inútiles: la autorización real la hace el
// backend (Policies) en cada petición. Para traslados se usa además
// `allowed_actions` de la API (S-67) en vez de repetir reglas de estado.
import type { Role } from '../api/types'

const PHARMACY: Role[] = ['auxiliar_farmacia', 'regente_farmacia']

export const permissions = {
  dispense: (role: Role) => PHARMACY.includes(role),
  authorizeControlled: (role: Role) => role === 'regente_farmacia',
  viewPatients: (role: Role) => [...PHARMACY, 'medico', 'auditor'].includes(role),
  createPrescription: (role: Role) => role === 'medico',
  viewInventory: (role: Role) => [...PHARMACY, 'auditor'].includes(role),
  adjustInventory: (role: Role) => role === 'regente_farmacia',
  viewTransfers: (role: Role) => [...PHARMACY, 'auditor'].includes(role),
  createTransfer: (role: Role) => PHARMACY.includes(role),
  resolveDiscrepancy: (role: Role) => role === 'regente_farmacia',
  manageUsers: (role: Role) => role === 'admin',
} satisfies Record<string, (role: Role) => boolean>

export type Permission = keyof typeof permissions

export interface NavItem {
  to: string
  label: string
  permission: Permission
}

// Orden = prioridad: la primera sección permitida es la pantalla de inicio.
export const NAV_ITEMS: NavItem[] = [
  { to: '/dispensacion', label: 'Dispensación', permission: 'dispense' },
  { to: '/autorizaciones', label: 'Autorizaciones', permission: 'authorizeControlled' },
  { to: '/traslados', label: 'Traslados', permission: 'viewTransfers' },
  { to: '/inventario', label: 'Inventario', permission: 'viewInventory' },
  { to: '/kardex', label: 'Kardex', permission: 'viewInventory' },
  { to: '/pacientes', label: 'Pacientes', permission: 'viewPatients' },
  { to: '/usuarios', label: 'Usuarios', permission: 'manageUsers' },
]

export function navItemsFor(role: Role): NavItem[] {
  return NAV_ITEMS.filter((item) => permissions[item.permission](role))
}

export const ROLE_LABELS: Record<Role, string> = {
  auxiliar_farmacia: 'Auxiliar de farmacia',
  regente_farmacia: 'Regente de farmacia',
  medico: 'Médico',
  auditor: 'Auditor',
  admin: 'Administrador',
}
