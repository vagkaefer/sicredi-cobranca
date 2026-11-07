<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use VagKaefer\SicrediCobranca\SicrediCobrancaServiceProvider;

abstract class TestCase extends Orchestra
{
    public const AUTH_SB = 'https://api-parceiro.sicredi.com.br/sb/auth/openapi/token';
    public const BASE_SB = 'https://api-parceiro.sicredi.com.br/sb/cobranca/boleto/v1';

    protected function getPackageProviders($app): array
    {
        return [SicrediCobrancaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('sicredi-cobranca.ambiente', 'sandbox');
        $app['config']->set('sicredi-cobranca.api_key', 'chave-teste');
        $app['config']->set('sicredi-cobranca.webhook.enabled', true);
        $app['config']->set('sicredi-cobranca.webhook.token', 'segredo');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function tokenResposta(string $access = 'tok-1', string $refresh = 'ref-1'): array
    {
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => 300,
            'refresh_expires_in' => 1800,
            'token_type' => 'Bearer',
            'scope' => 'cobranca',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function boletoCriadoResposta(): array
    {
        return [
            'txid' => '445488181811848',
            'qrCode' => "00020126930014br.gov.bcb.pix2571pix-qrcode-h.sicredi.com.br/qr/v2/cobv/abc630441AC\n\n",
            'linhaDigitavel' => '74891121150039736789903123451001187340000000050',
            'codigoBarras' => '74897937700000099891122224595067890312345109',
            'cooperativa' => '6789',
            'posto' => '03',
            'nossoNumero' => '211001292',
        ];
    }
}
