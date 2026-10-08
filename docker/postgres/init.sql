-- Se ejecuta UNA sola vez, cuando el volumen de datos está vacío
-- (docker-entrypoint-initdb.d). Crea la BD separada para las pruebas
-- automáticas, de modo que `make test` nunca toque la BD de desarrollo.
-- Si el volumen ya existía antes de este script: `make reset`.
CREATE DATABASE fartmar_test;
