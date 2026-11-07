# Despliegue en Hostinger (paso a paso)

Guía probada con un plan **Business Web Hosting** de Hostinger. El backend y el frontend se publican en **dos subdominios del mismo dominio**:

| Parte | Repositorio | Ejemplo de dirección |
|---|---|---|
| Backend (API Laravel) | [SISTEMAS-CURSO-BACKEND](https://github.com/yorchavez9/SISTEMAS-CURSO-BACKEND) | `https://cursoapi.kodevo.es` |
| Frontend (React) | [SISTEMA-CURSO-FRONTEND](https://github.com/yorchavez9/SISTEMA-CURSO-FRONTEND) | `https://sistemacurso.kodevo.es` |

En esta guía se usa `kodevo.es`; reemplázalo por tu dominio en cada paso.

> **Regla de oro:** los dos subdominios deben compartir el dominio principal (`*.kodevo.es`). La sesión viaja en una cookie que se comparte entre ellos con `SESSION_DOMAIN=.kodevo.es`. Con dominios totalmente distintos (p. ej. `miapp.com` y `miapi.net`) el inicio de sesión **no funciona** con esta configuración.

Orden recomendado: **1 → 9**. No saltes el paso 5 (variables de sesión): es la causa de casi todos los errores.

---

## 1. Requisitos en Hostinger

1. Un dominio o subdominios apuntando a Hostinger (DNS administrado por Hostinger o registros `A` hacia el hosting).
2. **SSH activado:** hPanel → *Avanzado → Acceso SSH*. Anota el comando de conexión (`ssh -p 65002 u123456789@IP`).
3. **PHP 8.3** para el subdominio del backend: hPanel → *Avanzado → Configuración de PHP*. Activa estas extensiones (la mayoría ya vienen activas):
   `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `iconv`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `simplexml`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`.
   - `bcmath` es **obligatoria**: los impuestos se calculan con ella.
   - `zip` y `gd` las usa la exportación a Excel.
4. Comprueba por SSH que la terminal usa la misma versión:
   ```bash
   php -v            # debe decir 8.3.x
   composer -V       # Composer 2.x (Hostinger lo trae instalado)
   ```

---

## 2. Base de datos

1. hPanel → *Bases de datos → Bases de datos MySQL* → crea una base y un usuario.
   Hostinger les antepone un prefijo, p. ej. base `u123456789_ventas`, usuario `u123456789_ventas`.
2. Anota **nombre de la base, usuario y contraseña**. El host es `localhost` (o `127.0.0.1`).

---

## 3. Crear los subdominios

hPanel → *Dominios → Subdominios*:

- `cursoapi` → para el backend.
- `sistemacurso` → para el frontend. Si vas a publicar el frontend con el despliegue automático desde GitHub (paso 7), Hostinger crea el sitio al conectarlo; no hace falta crear el subdominio a mano.

Activa **SSL** en ambos (hPanel → *Seguridad → SSL*). Todo debe funcionar con `https://`; las cookies de sesión se marcan como seguras.

---

## 4. Backend: subir el código

Por SSH:

```bash
cd ~/domains/cursoapi.kodevo.es
git clone https://github.com/yorchavez9/SISTEMAS-CURSO-BACKEND.git backend
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

### Hacer que el subdominio apunte a `backend/public`

Laravel solo debe exponer la carpeta `public/`. En Hostinger el subdominio sirve `~/domains/cursoapi.kodevo.es/public_html`. Reemplázala por un enlace a `backend/public`:

```bash
cd ~/domains/cursoapi.kodevo.es
mv public_html public_html_respaldo          # o bórrala si está vacía
ln -s backend/public public_html
```

Comprueba: `https://cursoapi.kodevo.es/up` debe responder la página verde de Laravel ("Application up").

> Si tu plan no permite el enlace simbólico, deja `public_html` y crea en ella un `.htaccess` que reescriba todo hacia `../backend/public/` — pero el enlace es lo recomendado, porque así nada fuera de `public/` queda accesible desde internet.

### Permisos de escritura

```bash
cd ~/domains/cursoapi.kodevo.es/backend
chmod -R 775 storage bootstrap/cache
```

---

## 5. Backend: archivo `.env` de producción

Edita `~/domains/cursoapi.kodevo.es/backend/.env` (con `nano .env` o desde el *Administrador de archivos*). Deja estas variables así — cambia solo lo que está entre `< >`:

```dotenv
APP_NAME="Sistema de Ventas"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cursoapi.kodevo.es
APP_TIMEZONE=America/Lima

LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<u123456789_ventas>
DB_USERNAME=<u123456789_ventas>
DB_PASSWORD=<contraseña de la base>

# Sesión compartida entre los dos subdominios (OBLIGATORIO)
SESSION_DRIVER=database
SESSION_COOKIE=sistemacurso_session
SESSION_DOMAIN=.kodevo.es
SESSION_SECURE_COOKIE=true
FRONTEND_URL=https://sistemacurso.kodevo.es
SANCTUM_STATEFUL_DOMAINS=sistemacurso.kodevo.es

QUEUE_CONNECTION=database
CACHE_STORE=database

SUNAT_API_BASE_URL=https://sunat.kodevo.es/api/v1
SUNAT_API_TIMEOUT=60
SUNAT_API_CONNECT_TIMEOUT=15
```

Qué hace cada una de las variables de sesión (si falla el login, revisa estas primero):

| Variable | Valor | Para qué sirve | Si está mal… |
|---|---|---|---|
| `SESSION_DOMAIN` | `.kodevo.es` (con el punto) | La cookie de sesión y la `XSRF-TOKEN` valen para todos los subdominios | Error **419** al registrar o iniciar sesión |
| `SESSION_COOKIE` | Un nombre propio, p. ej. `sistemacurso_session` | Evita choques con cookies `laravel-session` de otras apps del mismo dominio o de pruebas anteriores | **"CSRF token mismatch"** aunque todo lo demás esté bien |
| `FRONTEND_URL` | `https://sistemacurso.kodevo.es` (sin `/` final) | Origen permitido por CORS (`config/cors.php`) | El navegador bloquea las llamadas: error **CORS** en la consola |
| `SANCTUM_STATEFUL_DOMAINS` | `sistemacurso.kodevo.es` (sin `https://`) | Sanctum trata esas peticiones como sesión de navegador | Inicia sesión pero luego todo da **401** |
| `SESSION_SECURE_COOKIE` | `true` | Cookies solo por HTTPS | — |
| `APP_DEBUG` | `false` | No mostrar errores internos a los usuarios | Se exponen rutas y datos internos |

> **`APP_KEY` es crítica.** Cifra las credenciales de la API de facturación guardadas en la base. Copia su valor a un lugar seguro. Si se pierde o se regenera, hay que volver a ingresar las credenciales de todas las empresas.
>
> Las credenciales de la API (`api_key` / `api_secret`) **no** van en `.env`: se ingresan desde el sistema (paso 9).

Después de guardar:

```bash
cd ~/domains/cursoapi.kodevo.es/backend
php artisan migrate --force
php artisan db:seed --force        # OBLIGATORIO: permisos y catálogos SUNAT (unidades de medida, afectaciones del IGV, motivos…)
php artisan config:cache
php artisan route:cache
```

> Sin `db:seed` los formularios quedan vacíos (no hay unidades de medida ni tipos de afectación) y no se pueden crear productos ni emitir. Es seguro ejecutarlo varias veces: no duplica ni borra nada.

> Cada vez que cambies el `.env` ejecuta otra vez `php artisan config:cache` (o `config:clear`). Laravel lee la configuración en caché, no el archivo.

> **Configura las variables de sesión ANTES de abrir el sistema por primera vez.** Si el navegador ya visitó el backend con otra configuración, guarda cookies viejas con el mismo nombre que las nuevas y aparece **"CSRF token mismatch"**. Solución: cambiar `SESSION_COOKIE` (y `config:cache`) o borrar las cookies del dominio en el navegador (ver §11).

---

## 6. Backend: tareas programadas (cron) — obligatorio

Hostinger no permite procesos permanentes, así que la cola de envíos a SUNAT se procesa con cron. hPanel → *Avanzado → Cron Jobs* → **Personalizado**, frecuencia **cada minuto** (`* * * * *`). Crea **dos** tareas:

**a) Cola de trabajos** (envía los comprobantes a SUNAT, consulta su estado, descarga XML/CDR/PDF):

```bash
cd /home/<u123456789>/domains/cursoapi.kodevo.es/backend && php artisan queue:work --stop-when-empty --max-time=55 --tries=1 >> /dev/null 2>&1
```

**b) Programador de Laravel** (resumen diario de boletas a las 00:15 de Lima, etc.):

```bash
cd /home/<u123456789>/domains/cursoapi.kodevo.es/backend && php artisan schedule:run >> /dev/null 2>&1
```

- Reemplaza `<u123456789>` por tu usuario (lo ves con `echo $HOME` por SSH).
- Si `php` en cron no es la 8.3, usa la ruta completa que te muestre `which php` por SSH.
- **Sin la tarea (a)** las ventas se registran, pero los comprobantes se quedan en "Por enviar" y nunca llegan a SUNAT.

---

## 7. Frontend: publicar desde GitHub

1. hPanel → *Sitios web → Añadir sitio web* → opción para **conectar un repositorio de GitHub** (aplicación Node.js / Vite).
2. Repositorio: `SISTEMA-CURSO-FRONTEND`, rama `main`.
3. Configuración de compilación:

   | Campo | Valor |
   |---|---|
   | Estructura / framework | Vite |
   | Directorio raíz | `./` |
   | Comando de compilación | `npm run build` |
   | Directorio de salida | `dist` |
   | Versión de Node | 22.x |

4. Dominio: `sistemacurso.kodevo.es`.

### Dirección del backend (`VITE_API_URL`)

El frontend necesita saber dónde está la API. Se fija **al compilar**, en el archivo `.env.production` del repositorio del frontend:

```dotenv
VITE_API_URL=https://cursoapi.kodevo.es
```

- Sin `/` al final y con `https://`.
- Si usas otro dominio, **edita `.env.production`**, haz commit y push: Hostinger vuelve a compilar solo.
- Una variable `VITE_API_URL` definida en *Variables de entorno* de Hostinger tiene prioridad sobre el archivo, pero en la práctica Hostinger no siempre la pasa a la compilación; el archivo es lo más fiable.
- Cambiar la variable no basta: hay que **redistribuir** (nuevo despliegue) para que se compile otra vez.

### Rutas del SPA

El repositorio incluye `public/.htaccess`, que se copia a `dist/` al compilar y hace que direcciones como `/login` o `/comprobantes` sirvan `index.html`. Sin él, recargar cualquier página que no sea `/` da **404**.

---

## 8. Verificar el despliegue

Desde cualquier terminal (cambia los dominios):

```bash
# 1) El backend responde y aún no tiene usuarios
curl https://cursoapi.kodevo.es/api/v1/setup/estado
# → {"data":{"requiere_configuracion":true}}

# 2) CORS con credenciales y cookie compartida
curl -s -D - -o /dev/null -H "Origin: https://sistemacurso.kodevo.es" https://cursoapi.kodevo.es/sanctum/csrf-cookie
# Debe incluir:
#   Access-Control-Allow-Origin: https://sistemacurso.kodevo.es
#   Access-Control-Allow-Credentials: true
#   set-cookie: XSRF-TOKEN=...; domain=.kodevo.es; secure; samesite=lax

# 3) Las rutas del SPA no dan 404
curl -s -o /dev/null -w "%{http_code}\n" https://sistemacurso.kodevo.es/login      # → 200

# 4) El frontend compilado apunta al backend
curl -s https://sistemacurso.kodevo.es/ | grep -o 'assets/index-[^"]*\.js'
# y luego, con ese nombre:
curl -s https://sistemacurso.kodevo.es/assets/index-XXXX.js | grep -c cursoapi.kodevo.es   # → 1 o más
```

Si las cuatro comprobaciones salen bien, el sistema está listo.

---

## 9. Primer uso

1. Abre `https://sistemacurso.kodevo.es`. Al no existir usuarios, aparece **Configuración inicial**: registra el **superadministrador** (solo se puede hacer una vez).
2. Registra la **empresa** (RUC, razón social, dirección).
3. **Configuración → Credenciales API:** ingresa `api_key` y `api_secret` del entorno de **pruebas (beta)** y pulsa **Probar conexión**. Se guardan cifradas.
4. **Configuración → Establecimientos y series:** sincroniza con la API. Si otro sistema emite con la misma cuenta, crea **series propias** (p. ej. F002/B002) y márcalas como predeterminadas.
5. Emite una boleta de prueba de S/ 1.00 y confirma en **Comprobantes** que llega a **Aceptado** y que se pueden descargar XML, CDR y PDF (si se queda en "Por enviar", revisa el cron del paso 6).
6. **Opcional — datos de demostración** (categorías, 28 productos, presentaciones, clientes y stock inicial), útil para cursos o pruebas. Requiere haber registrado la empresa y sincronizado los establecimientos:
   ```bash
   cd ~/domains/cursoapi.kodevo.es/backend
   php artisan db:seed --class=DemoSeeder --force
   # con varias empresas, indica cuál:
   DEMO_EMPRESA_RUC=20123456789 php artisan db:seed --class=DemoSeeder --force
   ```
   No emite comprobantes ni llama a la API de facturación, y no duplica datos si se ejecuta otra vez. **No lo uses en una empresa real.**
7. Para pasar a producción real con SUNAT, sigue [despliegue.md §10](despliegue.md#10-paso-de-pruebas-beta-a-producción).

---

## 10. Actualizar a una nueva versión

**Backend** (por SSH):

```bash
cd ~/domains/cursoapi.kodevo.es/backend
php artisan down
mysqldump -u <usuario> -p <base> | gzip > ~/respaldo-$(date +%F-%H%M).sql.gz   # respaldo antes de migrar
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force        # actualiza permisos y catálogos SUNAT
php artisan config:cache && php artisan route:cache
php artisan queue:restart          # los trabajos en curso recargan el código nuevo
php artisan up
```

**Frontend:** basta con hacer push a `main`; Hostinger compila y publica solo (o pulsa *Redistribuir*). Recarga el navegador con **Ctrl+F5**.

> Nunca ejecutes `php artisan migrate:fresh` ni `db:wipe` en producción: borran todos los datos.

---

## 11. Solución de problemas

| Síntoma | Causa | Solución |
|---|---|---|
| `https://sistemacurso…/login` da **404** al recargar | Falta `.htaccess` del SPA en `dist/` | Verifica que `public/.htaccess` existe en el repositorio del frontend y redistribuye |
| El login dice **"No se pudo conectar con el servidor (https://sistemacurso…/api/v1)"** | El frontend se compiló sin `VITE_API_URL` | Revisa `.env.production` del frontend (paso 7) y redistribuye |
| No aparece el registro del superadministrador | Mismo problema anterior, o ya existe un usuario | Comprobación 1 del paso 8 |
| Error **419** ("CSRF token mismatch" / "La página expiró") | `SESSION_DOMAIN` no es `.kodevo.es`, o la configuración está en caché | Corrige el `.env` (paso 5) y ejecuta `php artisan config:cache` |
| **"CSRF token mismatch"** con `SESSION_DOMAIN` ya correcto (p. ej. funcionó el registro y falla la siguiente acción) | El navegador conserva cookies viejas de `cursoapi…` con el mismo nombre que las nuevas de `.kodevo.es`; el backend lee la vieja | Pon `SESSION_COOKIE=sistemacurso_session` + `php artisan config:cache`, o borra las cookies: candado de la barra de direcciones → *Cookies y datos de sitios* → eliminar las de `kodevo.es` y `cursoapi.kodevo.es`. Luego vuelve a iniciar sesión |
| En la consola del navegador: **"blocked by CORS policy"** | `FRONTEND_URL` distinto del origen real (con `/` final, `http` en vez de `https`, `www`…) | Debe ser exactamente `https://sistemacurso.kodevo.es`; luego `config:cache` |
| Inicia sesión pero todo devuelve **401** | `SANCTUM_STATEFUL_DOMAINS` no contiene el dominio del frontend | Ponlo sin `https://` y sin `/`; luego `config:cache` |
| Comprobantes en **"Por enviar"** para siempre | El cron de la cola (paso 6a) no corre | Revisa la ruta del cron y ejecútalo a mano por SSH para ver el error |
| El resumen diario de boletas no se envía solo | Falta el cron del programador (paso 6b) | Créalo; mientras tanto se puede enviar manualmente desde **Resumen diario** |
| Error **500** | Ver el detalle en el log | `tail -n 50 storage/logs/laravel.log` |
| "No se pueden descifrar las credenciales" | Cambió `APP_KEY` | Restaura la `APP_KEY` anterior o vuelve a ingresar las credenciales de cada empresa |
| `composer install` falla por extensiones | Falta alguna extensión de PHP | Actívala en *Configuración de PHP* (paso 1) |
| Cambié el `.env` y no se nota | Configuración en caché | `php artisan config:cache` |
| Sin unidades de medida ni tipos de IGV en los formularios; no se pueden crear productos | No se ejecutó el seeder base (o, en versiones anteriores, quedó en caché la lista vacía) | `php artisan db:seed --force` y `php artisan cache:clear`; luego Ctrl+F5 en el navegador. Para comprobar: `php artisan tinker --execute="echo DB::table('tipos_afectacion_igv')->count();"` debe dar 19 |
| `DemoSeeder` dice "No hay empresas registradas" | Aún no se registró la empresa | Regístrala en el sistema y vuelve a ejecutarlo |
| `DemoSeeder` crea productos pero no stock ("Sin almacenes") | Faltan establecimientos | Sincroniza *Establecimientos y series* y vuelve a ejecutarlo (solo agrega lo que falta) |

Registros útiles: `storage/logs/laravel.log` (backend), *Registros de tiempo de ejecución* del sitio del frontend en hPanel, y la pestaña **Red** (Network) de las herramientas del navegador para ver qué llamada falla y con qué código.
