// Dónde vive el token de Sanctum.
//
// Decisión: sessionStorage (no localStorage, no cookie).
// - Sobrevive a un F5: en farmacia, recargar la página no debe sacar al
//   usuario en medio de una dispensación.
// - Se borra al cerrar la pestaña y no se comparte entre pestañas ni queda
//   en disco tras cerrar el navegador (equipos compartidos en farmacia).
// - Frente a XSS, guardarlo solo en memoria apenas protege más (un script
//   inyectado puede hacer peticiones igual); la defensa real es no inyectar
//   HTML (React escapa) y la expiración corta del token (480 min).
// Solo se guarda el token: nunca datos de pacientes.
const KEY = 'fartmar.token'

export const tokenStorage = {
  get(): string | null {
    return sessionStorage.getItem(KEY)
  },
  set(token: string): void {
    sessionStorage.setItem(KEY, token)
  },
  clear(): void {
    sessionStorage.removeItem(KEY)
  },
}
