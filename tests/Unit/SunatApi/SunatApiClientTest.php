<?php

namespace Tests\Unit\SunatApi;

use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Integrations\SunatApi\LimitadorSolicitudes;
use App\Integrations\SunatApi\SunatApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SunatApiClientTest extends TestCase
{
    private const BASE = 'https://api.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        RateLimiter::clear('sunat-api:empresa:1:pruebas');
    }

    private function cliente(?LimitadorSolicitudes $lim = null): SunatApiClient
    {
        return new SunatApiClient('pk_test', 'sk_test', self::BASE, 10, 5, 'empresa:1:pruebas', $lim);
    }

    public function test_reintenta_ante_429_respetando_retry_after(): void
    {
        Http::fakeSequence(self::BASE.'/*')
            ->push(['estado' => 'error', 'mensaje' => 'Demasiadas solicitudes. Intenta de nuevo más tarde.'], 429, ['Retry-After' => '12'])
            ->push(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => ['id' => 1]], 201);

        $res = $this->cliente()->post('/facturas', ['serie' => 'F001']);

        $this->assertSame(201, $res->status);
        Http::assertSentCount(2);
        Sleep::assertSlept(fn ($d) => (int) $d->totalSeconds === 12, 1);
    }

    public function test_tras_varios_429_lanza_excepcion_para_reintentar_luego(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['estado' => 'error', 'mensaje' => 'Demasiadas solicitudes.'], 429)]);

        $this->expectException(SunatApiRateLimitException::class);
        try {
            $this->cliente()->get('/empresa');
        } finally {
            Http::assertSentCount(SunatApiClient::MAX_REINTENTOS_429 + 1);
        }
    }

    public function test_el_limitador_espera_cuando_se_agota_el_presupuesto_del_minuto(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => []])]);
        $cliente = $this->cliente(new LimitadorSolicitudes(porMinuto: 2, esperaMaximaSeg: 0));

        $cliente->get('/empresa');
        $cliente->get('/empresa');
        $this->expectException(SunatApiRateLimitException::class);
        try {
            $cliente->get('/empresa'); // la tercera excede 2/min y no se permite esperar
        } finally {
            Http::assertSentCount(2); // la tercera NO llegó a enviarse
        }
    }

    public function test_el_presupuesto_es_por_cuenta(): void
    {
        $lim = new LimitadorSolicitudes(porMinuto: 1, esperaMaximaSeg: 0);
        $this->assertTrue($lim->reservar('empresa:1:pruebas'));
        $this->assertFalse($lim->reservar('empresa:1:pruebas'));
        $this->assertTrue($lim->reservar('empresa:2:pruebas'));
        RateLimiter::clear('sunat-api:empresa:2:pruebas');
    }

    public function test_el_limitador_espera_si_se_le_permite(): void
    {
        $lim = new LimitadorSolicitudes(porMinuto: 1, esperaMaximaSeg: 120);
        $this->assertTrue($lim->reservar('empresa:1:pruebas'));
        // Con Sleep falso el tiempo no avanza: tras esperar lo permitido se rinde sin bucle infinito.
        $this->assertFalse($lim->reservar('empresa:1:pruebas'));
        Sleep::assertSleptTimes(2);
    }
}
