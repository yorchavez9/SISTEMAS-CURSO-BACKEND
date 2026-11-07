# Despliegue en producción

Guía para un servidor Linux propio (VPS con Ubuntu 24.04 o similar). Los comandos son orientativos: adáptalos a tu proveedor.

> **¿Hosting compartido (Hostinger u otro con hPanel/cPanel)?** Sigue la guía paso a paso [despliegue-hostinger.md](despliegue-hostinger.md): backend y frontend en dos subdominios, cola con cron y solución de problemas.

Hay dos formas de publicar el sistema:

| Modo | Cuándo | Qué configurar |
|---|---|---|
| **A. Mismo dominio** (esta guía) | VPS con Nginx | El SPA y la API comparten origen; no hace falta CORS ni `VITE_API_URL` |
| **B. Dos subdominios** (`app.midominio.pe` y `api.midominio.pe`) | Hosting compartido o servicios separados | `VITE_API_URL` en el frontend; `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` y `SESSION_DOMAIN=.midominio.pe` en el backend. Detalle en [despliegue-hostinger.md](despliegue-hostinger.md) |

## Arquitectura recomendada

```
                 https://ventas.midominio.pe
                            │
                        Nginx (TLS)
             ┌──────────────┼──────────────────┐
     /  (archivos del SPA)  │       /api  /sanctum  /up
     frontend/dist          │       PHP-FPM 8.3 → backend/public
                            │
   MySQL 8.4 ── Redis (cola, caché, límite de solicitudes)
                            │
   Supervisor: php artisan queue:work      cron: php artisan schedule:run
```

El SPA y la API se sirven **desde el mismo dominio**: la sesión de Sanctum viaja en una cookie `httpOnly` y no hace falta CORS. Para dos **subdominios** del mismo dominio (modo B) la configuración está probada en Hostinger: ver [despliegue-hostinger.md](despliegue-hostinger.md). Dominios completamente distintos (sin dominio padre común) no están soportados.

## 1. Paquetes

```bash
sudo apt install nginx mysql-server redis-server supervisor \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-bcmath php8.3-curl php8.3-xml php8.3-zip php8.3-redis php8.3-intl
# Composer 2 y Node.js 22 según sus guías oficiales
```

## 2. Código y dependencias

```bash
cd /var/www/sistema-ventas
# backend
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate   # solo la PRIMERA vez
# frontend (se compila y se sirven solo los archivos estáticos)
cd ../frontend
# Modo A (mismo dominio): VITE_API_URL debe quedar VACÍA. El repositorio trae .env.production
# con la URL de la instalación de demostración: edítalo o vacíalo antes de compilar.
npm ci && npm run build      # genera frontend/dist
```

## 3. Variables de entorno (`backend/.env`)

| Variable | Producción |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL`, `FRONTEND_URL` | `https://ventas.midominio.pe` |
| `SANCTUM_STATEFUL_DOMAINS` | `ventas.midominio.pe` |
| `SESSION_SECURE_COOKIE` | `true` |
| `DB_*` | Usuario de MySQL con permisos solo sobre la base del sistema |
| `QUEUE_CONNECTION` | `redis` |
| `CACHE_STORE` | `redis` (el limitador de 25 solicitudes por minuto por empresa usa bloqueos atómicos de la caché) |
| `REDIS_HOST`, `REDIS_PASSWORD` | Según tu Redis |
| `SUNAT_API_BASE_URL` | `https://sunat.kodevo.es/api/v1` |
| `LOG_LEVEL` | `warning` o `error` |

**`APP_KEY` es crítica:** cifra las credenciales de la API guardadas en la base. Guárdala en un gestor de secretos y respáldala aparte; si se pierde o se cambia, hay que volver a ingresar las credenciales de todas las empresas.

Las credenciales de la API **no** van en `.env`.

## 4. Base de datos

```bash
cd backend
php artisan migrate --force
php artisan db:seed --force     # permisos y catálogos SUNAT (obligatorio; idempotente)
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

Datos de demostración opcionales: `php artisan db:seed --class=DemoSeeder --force` (ver [despliegue-hostinger.md §9](despliegue-hostinger.md#9-primer-uso)).

Antes de cada migración en producción: **respaldo** (ver §8). No ejecutes nunca `migrate:fresh` ni `db:wipe` sobre la base de producción.

## 5. Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name ventas.midominio.pe;
    # ssl_certificate / ssl_certificate_key (p. ej. con certbot)

    root /var/www/sistema-ventas/frontend/dist;
    index index.html;
    client_max_body_size 10m;

    # API Laravel
    location ~ ^/(api|sanctum|up)(/|$) {
        root /var/www/sistema-ventas/backend/public;
        try_files $uri /index.php?$query_string;
    }
    location = /index.php {
        root /var/www/sistema-ventas/backend/public;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # SPA: cualquier otra ruta devuelve index.html (react-router)
    location / {
        try_files $uri /index.html;
    }
    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
server { listen 80; server_name ventas.midominio.pe; return 301 https://$host$request_uri; }
```

Permisos: el usuario de PHP-FPM (`www-data`) debe poder escribir en `backend/storage` y `backend/bootstrap/cache`.

## 6. Procesador de la cola (Supervisor)

`/etc/supervisor/conf.d/sistema-ventas-cola.conf`:

```ini
[program:sistema-ventas-cola]
command=php /var/www/sistema-ventas/backend/artisan queue:work redis --sleep=1 --tries=1 --max-time=3600
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/www/sistema-ventas/backend/storage/logs/cola.log
```

Cada trabajo define sus propios reintentos y esperas (`--tries=1` no los anula). Dos procesos son suficientes: la API admite 30 solicitudes por minuto por cuenta y el sistema se limita a 25.

```bash
sudo supervisorctl reread && sudo supervisorctl update
```

## 7. Programador de tareas (cron)

```cron
* * * * * www-data cd /var/www/sistema-ventas/backend && php artisan schedule:run >> /dev/null 2>&1
```

Hoy programa `sunat:enviar-resumenes` cada hora (minuto 15, hora de Lima): envía el resumen diario de las boletas pendientes de días anteriores en las empresas que usan el modo "resumen".

## 8. Respaldos

| Qué | Cómo | Frecuencia |
|---|---|---|
| Base de datos | `mysqldump --single-transaction --routines sistema_ventas \| gzip` | Diaria y antes de cada actualización |
| Archivos electrónicos | `backend/storage/app/private/comprobantes/` y `.../guias/` (XML, CDR y PDF) | Diaria |
| `APP_KEY` y `.env` | Gestor de secretos | En cada cambio |

Los XML y CDR son el respaldo legal de cada comprobante: consérvalos durante el plazo que exige SUNAT. Prueba la restauración al menos una vez.

## 9. Actualizar a una nueva versión

```bash
cd /var/www/sistema-ventas/backend
php artisan down
# respaldo de la base (§8)
git pull                              # o copiar la nueva versión
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan queue:restart             # los procesos de la cola recargan el código
cd ../frontend && npm ci && npm run build
cd ../backend && php artisan up
```

## 10. Paso de pruebas (beta) a producción

1. En **Configuración → Credenciales API**, registra las credenciales del entorno **producción** y pulsa **Probar conexión**. El sistema comprueba que la API responda con entorno `production`.
2. Cambia el **entorno activo** a producción en esa misma pantalla. El sistema no lo permite sin credenciales de producción verificadas. La barra superior cambia de "Entorno de pruebas" a "Producción".
3. Sincroniza **establecimientos y series**: los correlativos de producción son independientes de los de beta.
4. Emite un comprobante de bajo monto y verifica en el detalle que SUNAT lo aceptó y que se descargaron XML, CDR y PDF.
5. Si otro sistema emite con la misma cuenta, usa **series distintas** para evitar choques de numeración (el sistema renumera hasta 3 veces, pero conviene no depender de ello).

## 11. Monitoreo

- `GET /up`: chequeo de salud de Laravel.
- **Panel** del sistema: alertas de comprobantes sin respuesta de SUNAT por más de 15 minutos (suele indicar que la cola está detenida), errores y rechazos de los últimos 7 días, boletas sin resumen y stock bajo.
- `storage/logs/laravel.log` y `storage/logs/cola.log`.
- Tabla `api_solicitudes`: cada llamada a la API con su estado HTTP, duración y respuesta (sin credenciales).
- Trabajos fallidos: `php artisan queue:failed`.

## Lista de verificación de seguridad

- [ ] `APP_DEBUG=false`, HTTPS obligatorio y `SESSION_SECURE_COOKIE=true`.
- [ ] `.env` fuera del directorio público y con permisos `600`.
- [ ] `APP_KEY` respaldada en un gestor de secretos.
- [ ] Usuario de MySQL con privilegios mínimos; MySQL y Redis sin acceso desde internet.
- [ ] Respaldos automáticos probados.
- [ ] Un solo superadministrador; los demás usuarios con el rol mínimo necesario.
