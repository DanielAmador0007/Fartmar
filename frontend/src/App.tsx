import type { ReactNode } from 'react'
import { Navigate, Route, Routes } from 'react-router'
import { useCurrentUser } from './auth/AuthContext'
import { ChangePasswordPage } from './auth/ChangePasswordPage'
import { LoginPage } from './auth/LoginPage'
import { navItemsFor, type Permission } from './auth/permissions'
import { RequireAuth, RequirePermission } from './auth/RequireAuth'
import { Layout } from './components/Layout'
import { AssistantPage } from './features/assistant/AssistantPage'
import { AuthorizationsPage } from './features/dispensing/AuthorizationsPage'
import { DispensingPage } from './features/dispensing/DispensingPage'
import { InventoryPage } from './features/inventory/InventoryPage'
import { KardexPage } from './features/kardex/KardexPage'
import { PatientsPage } from './features/patients/PatientsPage'
import { TransferDetailPage } from './features/transfers/TransferDetailPage'
import { TransfersPage } from './features/transfers/TransfersPage'
import { UsersPage } from './features/users/UsersPage'

// Inicio: la primera sección que el rol puede usar.
function HomeRedirect() {
  const user = useCurrentUser()
  const first = navItemsFor(user.role)[0]
  return first ? <Navigate to={first.to} replace /> : <p>No hay secciones disponibles para tu rol.</p>
}

function guarded(permission: Permission, page: ReactNode) {
  return <RequirePermission permission={permission}>{page}</RequirePermission>
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route
        path="/cambiar-clave"
        element={
          <RequireAuth>
            <ChangePasswordPage />
          </RequireAuth>
        }
      />
      <Route
        element={
          <RequireAuth>
            <Layout />
          </RequireAuth>
        }
      >
        <Route index element={<HomeRedirect />} />
        <Route path="dispensacion" element={guarded('dispense', <DispensingPage />)} />
        <Route path="autorizaciones" element={guarded('authorizeControlled', <AuthorizationsPage />)} />
        <Route path="traslados" element={guarded('viewTransfers', <TransfersPage />)} />
        <Route path="traslados/:id" element={guarded('viewTransfers', <TransferDetailPage />)} />
        <Route path="inventario" element={guarded('viewInventory', <InventoryPage />)} />
        <Route path="kardex" element={guarded('viewInventory', <KardexPage />)} />
        <Route path="pacientes" element={guarded('viewPatients', <PatientsPage />)} />
        <Route path="asistente" element={guarded('askAssistant', <AssistantPage />)} />
        <Route path="usuarios" element={guarded('manageUsers', <UsersPage />)} />
        <Route path="*" element={<p>Página no encontrada.</p>} />
      </Route>
    </Routes>
  )
}
