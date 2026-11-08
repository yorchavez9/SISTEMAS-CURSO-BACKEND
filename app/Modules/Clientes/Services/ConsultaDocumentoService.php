<?php

namespace App\Modules\Clientes\Services;

use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Models\Empresa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Consulta de RUC (SUNAT) y DNI (RENIEC) para registrar clientes. Proveedores:
 *  - api_facturacion (por defecto): GET /buscar-documento de la API de facturación; usa sus mismas credenciales.
 *  - decolecta: https://api.decolecta.com con token propio de la empresa (documentación oficial:
 *    https://decolecta.gitbook.io/docs). GET /v1/sunat/ruc?numero= y GET /v1/reniec/dni?numero=,
 *    cabecera Authorization: Bearer <token>. Si falla, se usa la API de facturación como respaldo.
 *
 * Devuelve siempre la misma forma: tipo_doc, num_doc, razon_social, direccion, estado, condicion, ubigeo, fuente.
 */
class ConsultaDocumentoService
{
    public const PROVEEDORES = ['api_facturacion', 'decolecta'];

    public function __construct(private readonly SunatApiClientFactory $factory) {}

    /** @return array{0: array|null, 1: string|null} [datos normalizados, error] */
    public function consultar(Empresa $empresa, string $tipo, string $numero): array
    {
        $errorProveedor = null;
        if ($empresa->consulta_proveedor === 'decolecta' && $empresa->consulta_token) {
            [$datos, $errorProveedor] = $this->decolecta($empresa->consulta_token, $tipo, $numero);
            if ($datos) {
                return [$datos, null];
            }
        }
        // API de facturación (proveedor por defecto o respaldo). Sus excepciones (credenciales, conexión, límite) suben.
        $r = $this->factory->paraEmpresa($empresa)->get('/buscar-documento', ['tipo' => $tipo, 'numero' => $numero]);
        if ($r->ok() && is_array($d = $r->datos())) {
            // Respuesta real (Etapa 3): {tipo_doc, num_doc, razon_social, direccion, estado, condicion, source|fuente}
            return [[
                'tipo_doc' => (string) ($d['tipo_doc'] ?? $tipo), 'num_doc' => (string) ($d['num_doc'] ?? $numero),
                'razon_social' => $d['razon_social'] ?? null, 'direccion' => $d['direccion'] ?? null,
                'estado' => $d['estado'] ?? null, 'condicion' => $d['condicion'] ?? null, 'ubigeo' => $d['ubigeo'] ?? null,
                // Origen que informa la API (sunat, reniec, local…); se marca si se usó como respaldo de otro proveedor.
                'fuente' => ($d['source'] ?? $d['fuente'] ?? 'api_facturacion').($errorProveedor ? ' (respaldo)' : ''),
            ], null];
        }

        return [null, $errorProveedor ?? ($r->mensaje() ?? "HTTP {$r->status}")];
    }

    /** Prueba un proveedor con el RUC público de SUNAT (20131312955). */
    public function probar(Empresa $empresa, string $proveedor, ?string $token): array
    {
        if ($proveedor === 'decolecta') {
            [$d, $error] = $this->decolecta((string) $token, '6', '20131312955');
        } else {
            $copia = clone $empresa; // conserva el id (credenciales) sin guardar el cambio de proveedor
            $copia->consulta_proveedor = 'api_facturacion';
            try {
                [$d, $error] = $this->consultar($copia, '6', '20131312955');
            } catch (Throwable $e) {
                [$d, $error] = [null, $e->getMessage()];
            }
        }

        return $d
            ? ['ok' => true, 'mensaje' => "Conexión correcta: {$d['razon_social']}."]
            : ['ok' => false, 'mensaje' => 'No se pudo consultar: '.$error];
    }

    /** @return array{0: array|null, 1: string|null} */
    private function decolecta(string $token, string $tipo, string $numero): array
    {
        $ruta = $tipo === '6' ? '/v1/sunat/ruc' : '/v1/reniec/dni';
        try {
            $res = Http::baseUrl(rtrim((string) config('services.decolecta.base_url'), '/'))
                ->withToken($token)->acceptJson()->timeout(10)->connectTimeout(5)
                ->get($ruta, ['numero' => $numero]);
        } catch (ConnectionException $e) {
            return [null, 'No se pudo conectar con Decolecta.'];
        }
        if ($res->status() === 401 || $res->status() === 403) {
            return [null, 'Decolecta rechazó el token (revísalo en Configuración → Credenciales API).'];
        }
        $j = $res->json();
        if (! $res->successful() || ! is_array($j) || isset($j['error'])) {
            return [null, 'Decolecta: '.($j['error'] ?? $j['message'] ?? "HTTP {$res->status()}")];
        }

        if ($tipo === '6') {
            if (empty($j['razon_social'])) {
                return [null, 'Decolecta no devolvió datos para ese RUC.'];
            }
            $direccion = trim((string) ($j['direccion'] ?? ''));
            $lugar = implode(' - ', array_filter([$j['departamento'] ?? null, $j['provincia'] ?? null, $j['distrito'] ?? null], fn ($x) => $x && $x !== '-'));

            return [[
                'tipo_doc' => '6', 'num_doc' => (string) ($j['numero_documento'] ?? $numero), 'razon_social' => $j['razon_social'],
                'direccion' => $direccion !== '' && $direccion !== '-' ? trim($direccion.($lugar ? " $lugar" : '')) : null,
                'estado' => $j['estado'] ?? null, 'condicion' => $j['condicion'] ?? null, 'ubigeo' => $j['ubigeo'] ?? null, 'fuente' => 'decolecta',
            ], null];
        }

        $nombre = $j['full_name'] ?? trim(implode(' ', array_filter([$j['first_last_name'] ?? null, $j['second_last_name'] ?? null, $j['first_name'] ?? null])));
        if (! $nombre) {
            return [null, 'Decolecta no devolvió datos para ese DNI.'];
        }

        return [[
            'tipo_doc' => '1', 'num_doc' => (string) ($j['document_number'] ?? $numero), 'razon_social' => $nombre,
            'direccion' => null, 'estado' => null, 'condicion' => null, 'ubigeo' => null, 'fuente' => 'decolecta',
        ], null];
    }
}
