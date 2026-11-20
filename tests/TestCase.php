<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use VagKaefer\SicrediCobranca\SicrediCobrancaServiceProvider;

abstract class TestCase extends Orchestra
{
    public const AUTH_SB = 'https://api-parceiro.sicredi.com.br/sb/auth/openapi/token';
    public const BASE_SB = 'https://api-parceiro.sicredi.com.br/sb/cobranca/boleto/v1';

    // Host fictício: o de homologação da API Pix não é público.
    public const PIX_BASE = 'https://pix-hml.exemplo.test/api/v2';
    public const PIX_TOKEN = 'https://pix-hml.exemplo.test/oauth/token';
    public const PIX_CHAVE = '12345678000195';

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

        $app['config']->set('sicredi-cobranca.pix', array_merge(
            (array) $app['config']->get('sicredi-cobranca.pix', []),
            self::pixConfig(),
            ['webhook' => array_merge((array) $app['config']->get('sicredi-cobranca.pix.webhook', []), [
                'enabled' => true,
                'token' => 'segredo-pix',
            ])],
        ));
    }

    /**
     * Configuração Pix de teste, com certificado e chave falsos (nunca há conexão TLS real).
     *
     * @return array<string, mixed>
     */
    protected static function pixConfig(): array
    {
        return [
            'ambiente' => 'homologacao',
            'base_url' => self::PIX_BASE,
            'token_url' => self::PIX_TOKEN,
            'client_id' => 'cliente-pix',
            'client_secret' => 'segredo-cliente',
            'certificado' => self::pemFalso('certificado.pem', 'CERTIFICATE'),
            'chave' => self::pemFalso('chave.pem', 'PRIVATE KEY'),
            'chave_senha' => 'senha-da-chave',
            'chave_pix' => self::PIX_CHAVE,
            'diretorio_temporario' => self::diretorioTestes(),
        ];
    }

    protected static function diretorioTestes(): string
    {
        $dir = sys_get_temp_dir() . '/sicredi-pix-testes';
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        return $dir;
    }

    protected static function pemFalso(string $arquivo, string $tipo): string
    {
        $caminho = self::diretorioTestes() . '/' . $arquivo;
        file_put_contents($caminho, self::conteudoPem($tipo));

        return $caminho;
    }

    protected static function conteudoPem(string $tipo): string
    {
        return "-----BEGIN {$tipo}-----\nZmFsc28gcGFyYSB0ZXN0ZQ==\n-----END {$tipo}-----\n";
    }

    /**
     * @return array<string, mixed>
     */
    protected static function pixTokenResposta(string $access = 'pix-tok-1', int $expiresIn = 3600): array
    {
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $expiresIn, 'scope' => 'cob.write cob.read'];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function cobResposta(string $txid, string $status = 'ATIVA', array $extra = []): array
    {
        return array_merge([
            'calendario' => ['criacao' => '2026-11-20T13:00:00.000Z', 'expiracao' => 3600],
            'txid' => $txid,
            'revisao' => 0,
            'status' => $status,
            'valor' => ['original' => '150.00'],
            'chave' => self::PIX_CHAVE,
            'location' => 'pix-h.sicredi.com.br/qr/v2/cob/abc',
            'pixCopiaECola' => '00020101021226870014br.gov.bcb.pix2565pix-h.sicredi.com.br/qr/v2/cob/abc5204000053039865406150.005802BR6304ABCD',
        ], $extra);
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
