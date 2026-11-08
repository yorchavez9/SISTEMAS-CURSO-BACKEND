# Instalación en desarrollo

## Requisitos

| Herramienta | Versión probada | Notas |
|---|---|---|
| PHP | 8.3 | Extensiones: `pdo_mysql`, `mbstring`, `openssl`, `bcmath` (cálculo tributario), `curl`, `fileinfo`, `zip` |
| Composer | 2.9 | |
| MySQL | 8.4 | MariaDB no está probado |
| Node.js | 22 | npm incluido |

En Windows, [Laragon](https://laragon.org) trae PHP, MySQL y Composer. El proyecto vive en `C:\laragon\www\sistema-ejemplo-curso`.

## 1. Base de datos

Crea dos bases con `utf8mb4`: una para trabajar y otra **exclusiva** para las pruebas automáticas (las pruebas la vacían en cada ejecución).

```sql
CREATE DATABASE sistema_ventas      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE sistema_ventas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## 2. Backend (`backend/`)

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Revisa en `.env`:

| Variable | Valor en desarrollo |
|---|---|
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Datos de tu MySQL |
| `APP_URL` | `http://127.0.0.1:8000` |
| `FRONTEND_URL` | `http://localhost:5174` |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:5174,127.0.0.1:5174` |
| `SUNAT_API_BASE_URL` | `https://sunat.kodevo.es/api/v1` |
| `CACHE_STORE` | `database` (en producción se puede usar `redis`) |

> **No** agregues la api_key ni la api_secret al `.env`. Se registran desde el sistema y se guardan cifradas con `APP_KEY`.
> Si cambias `APP_KEY`, las credenciales guardadas dejan de poder descifrarse y hay que volver a ingresarlas.

```bash
php artisan migrate
php artisan db:seed                     # permisos y catálogos SUNAT (obligatorio)
php artisan storage:link                # imágenes de los productos (obligatorio)
php artisan serve --host=127.0.0.1 --port=8000
```

Datos de demostración (opcional, después de registrar la empresa y sincronizar establecimientos):
`php artisan db:seed --class=DemoSeeder`.

## 3. Programador de tareas (opcional en desarrollo)

El sistema **no usa cola de trabajos**: la API de facturación envía a SUNAT por su cuenta, el backend registra cada comprobante en la API en la misma solicitud y lee su estado al abrirlo o listarlo.

```bash
php artisan schedule:work   # cada minuto sunat:sincronizar; cada hora el resumen diario de boletas
```

Sin `schedule:work` todo funciona al usar las pantallas; solo dejan de actualizarse solos los documentos que nadie abre y el resumen diario hay que enviarlo desde **Resumen diario**.

## 4. Frontend (`frontend/`)

```bash
cd frontend
npm install
npm run dev
```

Abre `http://localhost:5174`. Vite redirige `/api` y `/sanctum` al backend en `127.0.0.1:8000` (cámbialo con `VITE_BACKEND_URL`), así el navegador y la API comparten origen y la sesión viaja en una cookie `httpOnly`.

El puerto 5174 es fijo (`strictPort`) porque el 5173 lo usa otro proyecto local. Si lo cambias, actualiza `FRONTEND_URL` y `SANCTUM_STATEFUL_DOMAINS`.

## 5. Primer uso

1. Al entrar por primera vez el sistema muestra la pantalla de registro del superadministrador (solo aparece si no hay usuarios).
   Alternativa por consola: `php artisan sistema:crear-admin correo@dominio.pe --name="Nombre"`.
2. **Configuración → Empresas:** registra la empresa (RUC, razón social, dirección, ubigeo).
3. **Configuración → Credenciales API:** ingresa la api_key y la api_secret del entorno de pruebas y pulsa **Probar conexión**.
4. **Configuración → Establecimientos y series:** pulsa **Sincronizar con la API**. Se recomienda usar series propias de este sistema (por ejemplo F002/B002) si otro sistema emite con la misma cuenta.
5. **Usuarios y roles:** usa **Agregar usuario** y asigna a cada uno un rol en la empresa.
6. Registra productos, abre la caja y emite una venta de prueba.

## 6. Pruebas automáticas

```bash
cd backend
php artisan test
```

Usan `sistema_ventas_test` (configurado en `phpunit.xml`), la cola en modo síncrono y respuestas simuladas de la API **copiadas de capturas reales** (`tests/Fixtures/etapa1`). No llaman a la API real.

Para el frontend: `npx tsc -b` (tipos) y `npm run build` (compilación de producción).

## 7. Herramienta de captura de la API

`php artisan sunat:captura` vuelve a ejecutar la captura de la Etapa 1 contra el entorno **beta**, con las credenciales de pruebas guardadas en la base. Emite documentos reales en beta: úsala solo cuando haga falta revalidar la API. La herramienta (`tools/api-capture/`) y sus capturas no se publican en este repositorio porque contienen respuestas reales de la cuenta; sin ella el comando no está disponible.
