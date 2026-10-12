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

function useUserMutations() {
  const queryClient = useQueryClient()
  const onSuccess = () => queryClient.invalidateQueries({ queryKey: ['users'] })
  return {
    create: useMutation({ mutationFn: usersApi.create, onSuccess }),
    update: useMutation({ mutationFn: ({ id, body }: { id: number; body: UserPatch }) => usersApi.update(id, body), onSuccess }),
  }
}

function CreateUserForm() {
  const { create } = useUserMutations()
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

export function UsersPage() {
  const me = useCurrentUser()
  const [page, setPage] = useState(1)
  const list = useQuery({ queryKey: ['users', page], queryFn: () => usersApi.list(page) })
  const { update } = useUserMutations()
  const guard = useSubmitGuard()
  const [resetFor, setResetFor] = useState<number | null>(null)
  const [tempPassword, setTempPassword] = useState('')

  const patch = (id: number, body: UserPatch) => void guard(() => update.mutateAsync({ id, body }))

  return (
    <>
      <h1>Usuarios</h1>
      <CreateUserForm />
      <section className="card">
        {list.isPending && <Loading />}
        <ErrorAlert error={list.error} action="ver usuarios" />
        <ErrorAlert error={update.error} action="modificar usuarios" />
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
                <tr key={u.id}>
                  <td>
                    {u.name} {u.must_change_password && <span className="badge badge-amber">Clave temporal</span>}
                  </td>
                  <td>{u.email}</td>
                  <td>
                    <select
                      aria-label={`Rol de ${u.name}`}
                      value={u.role}
                      disabled={update.isPending || u.id === me.id}
                      onChange={(e) => patch(u.id, { role: e.target.value as Role })}
                    >
                      {ROLES.map((r) => (
                        <option key={r} value={r}>
                          {ROLE_LABELS[r]}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td>{u.is_active ? 'Activo' : 'Inactivo'}</td>
                  <td className="actions">
                    <button type="button" disabled={update.isPending || u.id === me.id} onClick={() => patch(u.id, { is_active: !u.is_active })}>
                      {u.is_active ? 'Desactivar' : 'Activar'}
                    </button>
                    {resetFor === u.id ? (
                      <>
                        <input
                          type="password"
                          autoComplete="new-password"
                          aria-label="Nueva contraseña temporal"
                          minLength={12}
                          value={tempPassword}
                          onChange={(e) => setTempPassword(e.target.value)}
                        />
                        <button
                          type="button"
                          disabled={update.isPending || tempPassword.length < 12}
                          onClick={() => {
                            patch(u.id, { password: tempPassword })
                            setResetFor(null)
                            setTempPassword('')
                          }}
                        >
                          Guardar clave
                        </button>
                      </>
                    ) : (
                      <button type="button" onClick={() => setResetFor(u.id)}>
                        Restablecer clave
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {list.data && <Pagination page={page} lastPage={list.data.meta.last_page} onChange={setPage} />}
      </section>
    </>
  )
}
