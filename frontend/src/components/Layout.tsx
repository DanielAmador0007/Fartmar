import { NavLink, Outlet } from 'react-router'
import { useAuth, useCurrentUser } from '../auth/AuthContext'
import { navItemsFor, ROLE_LABELS } from '../auth/permissions'

export function Layout() {
  const user = useCurrentUser()
  const { logout } = useAuth()

  return (
    <div className="app">
      <header className="topbar">
        <strong className="brand">FARTMAR IPS</strong>
        <nav aria-label="Principal">
          {navItemsFor(user.role).map((item) => (
            <NavLink key={item.to} to={item.to}>
              {item.label}
            </NavLink>
          ))}
        </nav>
        <div className="session">
          <span>
            {user.name} · {ROLE_LABELS[user.role]}
          </span>
          <NavLink to="/cambiar-clave">Cambiar clave</NavLink>
          <button type="button" onClick={() => void logout()}>
            Salir
          </button>
        </div>
      </header>
      <main>
        <Outlet />
      </main>
    </div>
  )
}
