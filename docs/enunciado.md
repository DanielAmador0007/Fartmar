**Prueba Técnica — Desarrollador Full Stack Senior**

Sistema de dispensación de medicamentos, inventarios y traslados entre
bodegas

FARTMAR IPS \| Versión para proceso de selección

# 1. Resumen

| **Aspecto**           | **Detalle**                                                                |
|-----------------------|----------------------------------------------------------------------------|
| **Duración estimada** | 6 a 8 horas de trabajo efectivo                                            |
| **Plazo de entrega**  | 72 horas desde que recibes esta prueba                                     |
| **Stack**             | Obligatorio: PHP, Laravel, React, Docker y GitHub o GitLab (ver sección 5) |
| **Entrega**           | Repositorio Git (con historial de commits) y README                        |
| **Sustentación**      | 30 a 40 minutos. Incluye un cambio pequeño en vivo sobre tu código         |
| **Uso de IA**         | Permitido y esperado, pero debes declararlo (ver sección 8)                |

Si no alcanzas a hacer todo, prioriza y documenta qué dejaste fuera y
por qué. Valoramos más un núcleo correcto, seguro y bien probado que
muchas funciones a medias.

# 2. Contexto

FARTMAR IPS tiene varias bodegas y puntos de dispensación: una farmacia
central, una farmacia de urgencias y una bodega de hospitalización. Hoy
el control de medicamentos se hace con hojas de cálculo, lo que genera
sobreventas de stock, lotes vencidos entregados por error y traslados
entre bodegas sin trazabilidad.

Debes construir un sistema que permita dispensar medicamentos a
pacientes con base en una prescripción, controlar el inventario por
lote, y trasladar productos entre bodegas, con trazabilidad completa y
protección de los datos de los pacientes.

# 3. Roles de usuario

| **Rol**               | **Puede hacer**                                                                                              |
|-----------------------|--------------------------------------------------------------------------------------------------------------|
| **auxiliar_farmacia** | Dispensar, consultar inventario, crear y recibir traslados                                                   |
| **regente_farmacia**  | Todo lo anterior, aprobar traslados, autorizar medicamentos de control especial, hacer ajustes de inventario |
| **medico**            | Crear prescripciones y consultar pacientes                                                                   |
| **auditor**           | Solo lectura. Ve los datos del paciente enmascarados                                                         |
| **admin**             | Gestión de usuarios y catálogos                                                                              |

# 4. Reglas de negocio

Estas reglas son obligatorias y es sobre ellas que probaremos tu
solución.

| **Regla** | **Descripción**                                                                                                                                                                                                                                                                 |
|-----------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **RN-01** | Cada existencia se identifica por bodega + producto + lote. Cada lote tiene fecha de vencimiento y un lote vencido nunca puede dispensarse ni trasladarse.                                                                                                                      |
| **RN-02** | La dispensación consume los lotes en orden FEFO (primero en vencer, primero en salir). Una dispensación puede tomar cantidades de varios lotes.                                                                                                                                 |
| **RN-03** | El stock nunca puede quedar negativo, incluso si llegan solicitudes simultáneas por la última unidad.                                                                                                                                                                           |
| **RN-04** | Toda dispensación está asociada a un paciente y a una prescripción vigente. No se puede dispensar más de lo prescrito, pero se permiten dispensaciones parciales acumuladas.                                                                                                    |
| **RN-05** | Los medicamentos de control especial requieren la autorización de un segundo usuario con rol regente_farmacia.                                                                                                                                                                  |
| **RN-06** | Todo cambio de existencias genera un movimiento en el kardex (entrada, salida por dispensación, salida por traslado, entrada por traslado, ajuste). Los movimientos son inmutables: nunca se editan ni se borran. Las correcciones se hacen con movimientos de ajuste y motivo. |
| **RN-07** | Estados de un traslado: BORRADOR, SOLICITADO, APROBADO, EN_TRANSITO, RECIBIDO, RECIBIDO_PARCIAL, ANULADO. El stock sale del origen al despachar (pasa a tránsito) y entra al destino al recibir. Lo no recibido queda como discrepancia pendiente de resolución.                |
| **RN-08** | Segregación de funciones: quien solicita un traslado no puede aprobarlo.                                                                                                                                                                                                        |
| **RN-09** | Crear una dispensación debe ser idempotente: si el cliente reintenta la misma solicitud, no se duplica la salida de stock.                                                                                                                                                      |
| **RN-10** | Los datos del paciente son sensibles: acceso según rol, enmascarado para el auditor, bitácora de quién consultó qué paciente, y ningún dato personal en los logs.                                                                                                               |
| **RN-11** | El sistema alerta lotes que vencen en 90 días o menos y productos por debajo del stock mínimo definido por bodega.                                                                                                                                                              |

# 5. Alcance técnico

## Stack obligatorio

| **Capa**                    | **Tecnología**                                         | **Notas**                                                                                         |
|-----------------------------|--------------------------------------------------------|---------------------------------------------------------------------------------------------------|
| **Backend**                 | PHP 8.2 o superior y Laravel (versión estable vigente) | API REST                                                                                          |
| **Frontend**                | React                                                  | Se recomienda TypeScript y Vite                                                                   |
| **Base de datos**           | PostgreSQL 16 o SQL Server                             | Recomendada por el bloqueo de filas y las restricciones CHECK. MySQL 8 se acepta si lo justificas |
| **Contenedores**            | Docker y Docker Compose Opcional                       | Todo el sistema debe levantar con contenedores (Opcional)                                         |
| **Repositorio y CI/CD**     | Git con GitHub (Actions) o GitLab (CI)                 | A tu elección                                                                                     |
| **Calidad de código**       | Laravel Pint, Larastan y ESLint                        | Se ejecutan en el pipeline                                                                        |
| **Inteligencia artificial** | Proveedor de LLM configurable                          | API externa o modelo local (por ejemplo, Ollama)                                                  |
|                             |                                                        |                                                                                                   |

Cualquier librería adicional es libre, pero justifica en el README las
que sean relevantes para tus decisiones de diseño.

## Parte A — Backend y base de datos

- Modelo de datos relacional con migraciones de Laravel y modelos
  Eloquent. Defiende con restricciones en la base de datos, no solo en
  el código (por ejemplo, cantidades no negativas).

- API REST en Laravel documentada (OpenAPI o colección Postman), con
  autenticación (por ejemplo, Laravel Sanctum) y autorización por rol
  con Policies o Gates.

- Validación de entradas con Form Requests y respuestas con API
  Resources. La lógica de negocio debe vivir fuera de los controladores.

- Operaciones mínimas: dispensar, consultar inventario por
  bodega/producto/lote, consultar kardex,
  crear/aprobar/despachar/recibir/anular traslados, y alertas de
  vencimiento y stock mínimo.

- Las operaciones que modifican stock deben ser transaccionales y
  seguras ante concurrencia.

- Bitácora de auditoría de accesos a datos de pacientes y de las
  operaciones sensibles.

## Parte B — Frontend

Cuatro pantallas funcionales en React (se recomienda TypeScript):

- Dispensación: buscar paciente y prescripción, ver lotes asignados por
  FEFO y confirmar.

- Traslados: crear, aprobar, despachar y recibir, mostrando el estado y
  las discrepancias.

- Inventario: consulta por bodega con las alertas de vencimiento y stock
  mínimo.

- Kardex: historial de movimientos filtrable por producto, lote y
  bodega.

Se evaluará el manejo de errores comprensibles para el usuario (stock
insuficiente, lote vencido, falta de autorización), la prevención de
doble clic y la claridad de uso en un entorno de trabajo rápido.

## Parte C — Inteligencia artificial

Implementa un asistente de consulta de inventario en lenguaje natural
(por ejemplo: "¿qué lotes de acetaminofén vencen en los próximos 60 días
en la farmacia central?").

- El modelo no puede ejecutar SQL libre. Debe responder usando
  herramientas (function calling) predefinidas, de solo lectura, que
  respeten el rol del usuario.

- No envíes datos de pacientes al modelo. Si usas un proveedor externo,
  justifícalo; si prefieres un modelo local (por ejemplo, con Ollama o
  cualquier otra), explica el compromiso.

- El proveedor del modelo debe ser configurable por variable de entorno
  e incluir un modo simulado (mock), para que podamos evaluar sin llaves
  de API.

- Impleméntalo como un servicio de Laravel con una interfaz de
  proveedor, de modo que cambiar de modelo no obligue a tocar el resto
  de la aplicación.

- Debe resistir inyección de instrucciones, incluso si el texto
  malicioso viene de un campo como las observaciones de un traslado.

- Incluye un conjunto de evaluación de al menos 10 preguntas con su
  respuesta esperada y un script que reporte los aciertos.

- Define qué hace el asistente cuando no sabe o la pregunta está fuera
  de su alcance.

## Parte D — Docker y DevOps

- Dockerfile multi-stage para la API (PHP-FPM con Nginx) y para el
  frontend (build de React), con usuario no root y .dockerignore.

- docker-compose con base de datos (con volumen), API y frontend, con
  healthchecks, dependencias con condición de salud y un .env.example.
  Sin secretos en el repositorio.

- Migraciones y datos semilla automáticos y repetibles. Todo debe
  levantar con un solo comando desde cero.

- Pipeline de CI/CD versionado en el repositorio (GitHub Actions o
  GitLab CI, a tu elección): lint (Pint y ESLint), análisis estático
  (Larastan), pruebas del backend y del frontend, build de imágenes, y
  despliegue simulado a staging con aprobación manual para producción.

- Endpoints /health y /ready, y logs estructurados con identificador de
  correlación.

- Un documento breve (máx. 1 página) con la estrategia de despliegue,
  rollback y respaldo/restauración de la base de datos.

## Parte E — Calidad y documentación

- Pruebas automáticas (PHPUnit o Pest en el backend, y Vitest o Jest en
  React), como mínimo de: asignación FEFO, la máquina de estados del
  traslado y un caso de concurrencia sobre la última unidad.

- README con: cómo ejecutar todo en un comando, decisiones de diseño con
  al menos 3 compromisos (trade-offs) que tomaste, supuestos, y qué
  quedó fuera y por qué.

- Archivo AI_USAGE.md (ver sección 8).

# 6. Datos semilla sugeridos

Usa únicamente datos sintéticos. No uses datos reales de pacientes.

- 3 bodegas: Farmacia Central, Farmacia Urgencias, Bodega
  Hospitalización.

- 6 medicamentos, entre ellos uno de control especial, con 2 o 3 lotes
  cada uno. Incluye al menos un lote vencido y uno que venza en menos de
  30 días.

- 3 pacientes sintéticos con prescripciones vigentes, una de ellas con
  un medicamento de control especial.

- Un usuario por cada rol.

# 7. Entregables

- Repositorio Git con historial de commits que muestre cómo avanzaste.

- README.md, AI_USAGE.md y el documento de despliegue.

- Documentación de la API (OpenAPI o Postman).

- Script o comando para ejecutar la evaluación del asistente de IA.

# 8. Reglas de juego

- Puedes usar herramientas de IA para programar. En AI_USAGE.md describe
  qué herramientas usaste, para qué tareas, un ejemplo de algo que
  aceptaste, y uno de algo que rechazaste o corregiste, y por qué.

- Debes poder explicar y modificar cualquier parte de tu código en la
  sustentación.

- No uses información real de pacientes ni de la IPS.

- Si algo es ambiguo, toma una decisión razonable y regístrala como
  supuesto. No es necesario preguntar.

# 9. Sustentación

Presentarás tu solución en 10 a 20 minutos, responderás preguntas sobre
tus decisiones, y realizarás un cambio pequeño en vivo sobre tu propio
código.

# 10. Qué evaluaremos

| **Área**                                        | **Peso** |
|-------------------------------------------------|----------|
| Modelo de datos e integridad                    | 15 %     |
| Dispensación: FEFO, concurrencia e idempotencia | 20 %     |
| Traslados y kardex                              | 15 %     |
| Seguridad y privacidad de datos                 | 10 %     |
| Frontend y experiencia de uso                   | 10 %     |
| Inteligencia artificial                         | 10 %     |
| Docker y DevOps                                 | 12 %     |
| Pruebas, documentación y calidad del código     | 8 %      |
