import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { apiRequest } from '../../api/client'
import type { DataEnvelope, Paginated, Role, User } from '../../api/types'
import { useCurrentUser } from '../../auth/AuthContext'
import { ROLE_LABELS } from '../../auth/permissions'
import { ErrorAlert, Loading, Pagination, SuccessAlert } from '../../components/ui'
import { useSubmitGuard } from '../../lib/useSubmitGuard'

const ROLES = Object.keys(ROLE_LABELS) as Role[]

type UserPatch = Partial<Pick<User, 'name' | 'role' | 'is_active'>> & { password?: string }

const usersApi = {
  list: (page: number) => apiRequest<Paginated<User>>('/users', { query: { page } }),
  create: (body: { name: string; email: string; password: string; role: Role }) =>
    apiRequest<DataEnvelope<User>>('/users', { method: 'POST', body }).then((r) => r.data),
  update: (id: number, body: UserPatch) =>
    apiRequest<DataEnvelope<User>>(`/users/${id}`, { method: 'PATCH', body }).then((r) => r.data),
}

function useCreateUser() {
  const queryClient = useQueryClient()
  return useMutation({ mutationFn: usersApi.create, onSuccess: () => queryClient.invalidateQueries({ queryKey: ['users'] }) })
}

function useUpdateUser(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (body: UserPatch) => usersApi.update(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['users'] }),
  })
}

function CreateUserForm() {
  const create = useCreateUser()
  const guard = useSubmitGuard()
  const [form, setForm] = useState({ name: '', email: '', password: '', role: 'auxiliar_farmacia' as Role })

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    void guard(() => create.mutateAsync(form).then(() => setForm({ name: '', email: '', password: '', role: 'auxiliar_farmacia' })))
  }

  return (
    <form className="card inline-form" onSubmit={handleSubmit} aria-label="Crear usuario">
      <h2 className="full">Crear usuario</h2>
      <label>
        Nombre
        <input required minLength={3} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
      </label>
      <label>
        Correo
        <input type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
      </label>
      <label>
        Contraseña temporal (12+)
        <input type="password" autoComplete="new-password" required minLength={12} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
      </label>
      <label>
        Rol
        <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as Role })}>
          {ROLES.map((r) => (
            <option key={r} value={r}>
              {ROLE_LABELS[r]}
            </option>
          ))}
        </select>
      </label>
      <button type="submit" className="primary" disabled={create.isPending}>
        {create.isPending ? 'Creando…' : 'Crear'}
      </button>
      <div className="full">
        <ErrorAlert error={create.error} action="crear usuarios" />
        {create.isSuccess && <SuccessAlert>Usuario creado. Deberá cambiar la contraseña temporal al ingresar.</SuccessAlert>}
      </div>
    </form>
  )
}

// Una fila con su propia mutación: el estado "guardando" y el error son de ese usuario.
// El rol se cambia con "Guardar rol" (cambiar el rol revoca los tokens del usuario).
function UserRow({ user, isMe }: { user: User; isMe: boolean }) {
  const update = useUpdateUser(user.id)
  const guard = useSubmitGuard()
  const [role, setRole] = useState<Role>(user.role)
  const [resetting, setResetting] = useState(false)
  const [tempPassword, setTempPassword] = useState('')
  const busy = update.isPending

  const patch = (body: UserPatch, onSuccess?: () => void) => void guard(() => update.mutateAsync(body).then(onSuccess))

  return (
    <tr>
      <td>
        {user.name} {user.must_change_password && <span className="badge badge-amber">Clave temporal</span>}
      </td>
      <td>{user.email}</td>
      <td>
        <select aria-label={`Rol de ${user.name}`} value={role} disabled={busy || isMe} onChange={(e) => setRole(e.target.value as Role)}>
          {ROLES.map((r) => (
            <option key={r} value={r}>
              {ROLE_LABELS[r]}
            </option>
          ))}
        </select>
        {role !== user.role && (
          <>
            <button type="button" disabled={busy} onClick={() => patch({ role })}>
              Guardar rol
            </button>
            <button type="button" disabled={busy} onClick={() => setRole(user.role)}>
              Descartar
            </button>
          </>
        )}
      </td>
      <td>{user.is_active ? 'Activo' : 'Inactivo'}</td>
      <td>
        <div className="actions">
          <button type="button" disabled={busy || isMe} onClick={() => patch({ is_active: !user.is_active })}>
            {user.is_active ? 'Desactivar' : 'Activar'}
          </button>
          {resetting ? (
            <>
              <input
                type="password"
                autoComplete="new-password"
                aria-label={`Nueva contraseña temporal de ${user.name}`}
                minLength={12}
                value={tempPassword}
                onChange={(e) => setTempPassword(e.target.value)}
              />
              <button
                type="button"
                disabled={busy || tempPassword.length < 12}
                onClick={() =>
                  // Se limpia solo si la API aceptó la clave; si falla, el admin puede corregirla.
                  patch({ password: tempPassword }, () => {
                    setResetting(false)
                    setTempPassword('')
                  })
                }
              >
                Guardar clave
              </button>
            </>
          ) : (
            <button type="button" onClick={() => setResetting(true)}>
              Restablecer clave
            </button>
          )}
        </div>
        <ErrorAlert error={update.error} action="modificar usuarios" />
      </td>
    </tr>
  )
}

export function UsersPage() {
  const me = useCurrentUser()
  const [page, setPage] = useState(1)
  const list = useQuery({ queryKey: ['users', page], queryFn: () => usersApi.list(page) })

  return (
    <>
      <h1>Usuarios</h1>
      <CreateUserForm />
      <section className="card" aria-label="Usuarios">
        {list.isPending && <Loading />}
        <ErrorAlert error={list.error} action="ver usuarios" />
        {list.data && (
          <table>
            <thead>
              <tr>
                <th>Nombre</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              {list.data.data.map((u) => (
                // key con el rol: tras guardar, la fila arranca con el rol nuevo.
                <UserRow key={`${u.id}-${u.role}`} user={u} isMe={u.id === me.id} />
              ))}
            </tbody>
          </table>
        )}
        {list.data && <Pagination page={page} lastPage={list.data.meta.last_page} onChange={setPage} />}
      </section>
    </>
  )
}
