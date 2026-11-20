<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests\Unit\Pix;

use InvalidArgumentException;
use VagKaefer\SicrediCobranca\Pix\Enums\AmbientePix;
use VagKaefer\SicrediCobranca\Pix\PixConfig;
use VagKaefer\SicrediCobranca\Tests\TestCase;

class PixConfigTest extends TestCase
{
    public function test_producao_usa_urls_padrao(): void
    {
        $config = PixConfig::fromArray(array_merge(self::pixConfig(), ['ambiente' => 'producao', 'base_url' => null, 'token_url' => '']));

        $this->assertSame(AmbientePix::Producao, $config->ambiente);
        $this->assertSame('https://api-pix.sicredi.com.br/api/v2', $config->baseUrl);
        $this->assertSame('https://api-pix.sicredi.com.br/oauth/token', $config->tokenUrl);
        $this->assertSame(PixConfig::ESCOPOS_PADRAO, $config->escopos);
    }

    public function test_homologacao_exige_base_url_e_token_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Em homologação, base_url e token_url são obrigatórios');

        PixConfig::fromArray(array_merge(self::pixConfig(), ['base_url' => null, 'token_url' => null]));
    }

    public function test_credenciais_obrigatorias(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client_id, client_secret');

        PixConfig::fromArray(array_merge(self::pixConfig(), ['client_id' => '', 'client_secret' => ' ']));
    }

    public function test_url_customizada_sem_barra_final(): void
    {
        $config = PixConfig::fromArray(array_merge(self::pixConfig(), ['base_url' => self::PIX_BASE . '/']));

        $this->assertSame(self::PIX_BASE, $config->baseUrl);
    }

    public function test_opcoes_http_trazem_cert_e_ssl_key_com_senha(): void
    {
        $config = PixConfig::fromArray(array_merge(self::pixConfig(), ['certificado_senha' => 'senha-cert']));
        $opcoes = $config->opcoesHttp();

        $this->assertSame([$config->certificado, 'senha-cert'], $opcoes['cert']);
        $this->assertSame([$config->chave, 'senha-da-chave'], $opcoes['ssl_key']);

        $semSenha = PixConfig::fromArray(array_merge(self::pixConfig(), ['chave_senha' => null]))->opcoesHttp();
        $this->assertIsString($semSenha['cert']);
        $this->assertIsString($semSenha['ssl_key']);
    }

    public function test_conteudo_pem_vira_arquivo_temporario_600_reaproveitado(): void
    {
        $dados = array_merge(self::pixConfig(), [
            'certificado' => self::conteudoPem('CERTIFICATE'),
            'chave' => self::conteudoPem('PRIVATE KEY'),
        ]);

        $a = PixConfig::fromArray($dados);
        $b = PixConfig::fromArray($dados);

        $this->assertFileExists($a->chave);
        $this->assertStringStartsWith(self::diretorioTestes(), $a->chave);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($a->chave)), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($a->certificado)), -4));
        $this->assertSame($a->chave, $b->chave);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', (string) file_get_contents($a->chave));
    }

    public function test_arquivo_inexistente(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('arquivo do certificado não encontrado');

        PixConfig::fromArray(array_merge(self::pixConfig(), ['certificado' => '/nao/existe.pem']));
    }

    public function test_detecta_certificado_em_der(): void
    {
        $der = self::diretorioTestes() . '/certificado.der';
        file_put_contents($der, "\x30\x82\x03\x55binario");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('openssl x509 -inform der');

        PixConfig::fromArray(array_merge(self::pixConfig(), ['certificado' => $der]));
    }

    public function test_detecta_certificado_e_chave_trocados(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Certificado e chave estão trocados?');

        PixConfig::fromArray(array_merge(self::pixConfig(), [
            'certificado' => self::pemFalso('trocado-cert.pem', 'PRIVATE KEY'),
            'chave' => self::pemFalso('trocado-chave.pem', 'CERTIFICATE'),
        ]));
    }

    public function test_chave_cache_muda_com_escopos(): void
    {
        $a = PixConfig::fromArray(self::pixConfig());
        $b = PixConfig::fromArray(array_merge(self::pixConfig(), ['escopos' => 'cob.read']));

        $this->assertNotSame($a->chaveCache(), $b->chaveCache());
        $this->assertStringNotContainsString('segredo-cliente', $a->chaveCache());
    }

    public function test_chave_cache_muda_com_o_certificado_e_impressao_digital(): void
    {
        $a = PixConfig::fromArray(self::pixConfig());
        $outro = self::diretorioTestes() . '/outro-certificado.pem';
        file_put_contents($outro, "-----BEGIN CERTIFICATE-----\nb3V0cm8=\n-----END CERTIFICATE-----\n");
        $b = PixConfig::fromArray(array_merge(self::pixConfig(), ['certificado' => $outro]));

        $this->assertNotSame($a->chaveCache(), $b->chaveCache());
        $this->assertSame(strtoupper(sha1('falso para teste')), $a->impressaoDigital());
        $this->assertSame(strtoupper(hash('sha256', 'outro')), $b->impressaoDigital('sha256'));
    }

    public function test_ambiente_aceita_apelidos(): void
    {
        $this->assertSame(AmbientePix::Homologacao, AmbientePix::parse('sandbox'));
        $this->assertSame(AmbientePix::Producao, AmbientePix::parse('PROD'));

        $this->expectException(InvalidArgumentException::class);
        AmbientePix::parse('qualquer');
    }
}
