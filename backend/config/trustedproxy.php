<?php

/*
| Proxies de confianza (lo lee el middleware TrustProxies de Laravel en cada
| petición, así que funciona con config:cache).
|
| Por defecto NO se confía en ningún proxy: detrás de Nginx con FastCGI,
| Nginx entrega REMOTE_ADDR = IP del cliente ($remote_addr) y además
| reescribe X-Forwarded-For con esa misma IP (docker/nginx/fastcgi-api.conf).
| Confiar en "*" aquí sería un error: REMOTE_ADDR ya es el cliente, y un
| cliente podría falsificar X-Forwarded-For para saltarse los limitadores
| de login o ensuciar la IP de audit_logs.
|
| Solo se define TRUSTED_PROXIES si la app queda detrás de un proxy HTTP
| (no FastCGI): lista de IPs/CIDR separadas por coma. Si hay un balanceador
| delante de Nginx, la IP real se resuelve en Nginx (set_real_ip_from), no
| aquí. Ver docs/DEPLOY.md y supuesto S-100.
*/

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [

    'proxies' => $proxies === '' ? null : $proxies,

];
