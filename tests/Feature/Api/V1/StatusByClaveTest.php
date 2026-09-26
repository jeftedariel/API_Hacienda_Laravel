<?php

use App\Models\Company;
use App\Models\HaciendaCredential;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Consulta de estado por clave: verificar un comprobante que esta
 * instalación NO emitió. Hacienda mockeado vía Http::fake.
 */
function companyForStatus(string $environment = 'stag'): array
{
    $user = User::create([
        'full_name' => 'Owner', 'user_name' => 'owner', 'email' => 'owner@example.com',
        'about' => '', 'country' => 'crc', 'status' => '1',
        'legacy_timestamp' => time(), 'last_access' => time(),
        'password' => password_hash('secret123', PASSWORD_BCRYPT, ['cost' => 4]),
        'avatar' => '0', 'settings' => null,
    ]);

    $company = Company::create([
        'id' => $user->id, 'owner_user_id' => $user->id,
        'nombre' => 'ACME S.A.', 'tipo_cedula' => '02', 'cedula' => '3101234567',
        'nombre_comercial' => 'ACME', 'email' => 'facturas@acme.cr',
        'id_provincia' => '1', 'id_canton' => '01', 'id_distrito' => '01', 'id_barrio' => '01',
        'sennas' => 'Oficinas centrales', 'tel_cod_pais' => '506', 'tel_numero' => '22001100',
        'env' => $environment === 'prod' ? 'api-prod' : 'api-stag',
        'situacion' => 'normal', 'tipo_cambio' => '1',
    ]);

    HaciendaCredential::create([
        'company_id' => $company->id, 'environment' => $environment,
        'username' => 'cpf-03-0101-234567@stag.comprobanteselectronicos.go.cr',
        'password' => 'atv-secret', 'p12_download_code' => 'p12code', 'pin' => '1234',
    ]);

    return [$user, $company];
}

/** Token Sanctum de la empresa, como lo usa el resto de la API v1. */
function tokenFor(User $user): string
{
    return $user->createToken('test', ['master'])->plainTextToken;
}

/** Clave de 50 dígitos de un comprobante ajeno. */
const CLAVE_AJENA = '50601012600310199988800100001010000000001199999999';

test('consulta el estado de un comprobante que la empresa no emitió', function () {
    [$user] = companyForStatus();

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'bearer'], 200),
        '*recepcion*' => Http::response([
            'clave' => CLAVE_AJENA,
            'ind-estado' => 'aceptado',
            'respuesta-xml' => base64_encode('<MensajeHacienda/>'),
        ], 200),
    ]);

    $respuesta = $this->withToken(tokenFor($user))
        ->getJson('/api/v1/hacienda/status?clave='.CLAVE_AJENA)
        ->assertOk();

    expect($respuesta->json('clave'))->toBe(CLAVE_AJENA)
        ->and($respuesta->json('environment'))->toBe('stag')
        ->and($respuesta->json('hacienda.ind-estado'))->toBe('aceptado');
});

test('rechaza una clave con formato inválido', function () {
    [$user] = companyForStatus();

    $this->withToken(tokenFor($user))
        ->getJson('/api/v1/hacienda/status?clave=123')
        ->assertStatus(422);

    $this->withToken(tokenFor($user))
        ->getJson('/api/v1/hacienda/status')
        ->assertStatus(422);
});

test('exige credenciales del ambiente pedido', function () {
    [$user] = companyForStatus('stag');

    $this->withToken(tokenFor($user))
        ->getJson('/api/v1/hacienda/status?clave='.CLAVE_AJENA.'&environment=prod')
        ->assertStatus(422);
});

test('responde 404 cuando Hacienda no tiene la clave', function () {
    [$user] = companyForStatus();

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token'], 200),
        // Hacienda devuelve cuerpo vacío para una clave que no conoce.
        '*recepcion*' => Http::response('', 404),
    ]);

    $this->withToken(tokenFor($user))
        ->getJson('/api/v1/hacienda/status?clave='.CLAVE_AJENA)
        ->assertStatus(404);
});

test('responde 502 si no se puede hablar con Hacienda', function () {
    [$user] = companyForStatus();

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token'], 200),
        '*recepcion*' => fn () => throw new ConnectionException('timeout'),
    ]);

    $this->withToken(tokenFor($user))
        ->getJson('/api/v1/hacienda/status?clave='.CLAVE_AJENA)
        ->assertStatus(502);
});

test('sin token no se consulta nada', function () {
    $this->getJson('/api/v1/hacienda/status?clave='.CLAVE_AJENA)
        ->assertStatus(401);
});
