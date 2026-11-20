<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests\Feature\Pix;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use VagKaefer\SicrediCobranca\Exceptions\PixAutenticacaoException;
use VagKaefer\SicrediCobranca\Exceptions\PixException;
use VagKaefer\SicrediCobranca\Facades\SicrediPix;
use VagKaefer\SicrediCobranca\Tests\TestCase;

class PixTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function txid(): string
    {
        return str_repeat('A', 32);
    }

    public function test_token_com_basic_escopos_e_cache(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/cob/*' => Http::response(self::cobResposta($this->txid())),
        ]);

        SicrediPix::consultarCobranca($this->txid());
        SicrediPix::consultarCobranca($this->txid());

        // Um token para as duas chamadas: não autentica por chamada.
        Http::assertSentCount(3);
        Http::assertSent(function (Request $r) {
            return $r->url() === self::PIX_TOKEN
                && $r->method() === 'POST'
                && $r->hasHeader('Authorization', 'Basic ' . base64_encode('cliente-pix:segredo-cliente'))
                && str_contains($r->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded')
                && $r['grant_type'] === 'client_credentials'
                && $r['scope'] === 'cob.write cob.read pix.read pix.write webhook.read webhook.write';
        });
        Http::assertSent(fn(Request $r) => $r->url() === self::PIX_BASE . '/cob/' . $this->txid() && $r->hasHeader('Authorization', 'Bearer pix-tok-1'));
    }

    public function test_renova_quando_expira(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::sequence()
                ->push(self::pixTokenResposta('pix-tok-1', 60))
                ->push(self::pixTokenResposta('pix-tok-2', 60)),
            self::PIX_BASE . '/cob/*' => Http::response(self::cobResposta($this->txid())),
        ]);

        SicrediPix::consultarCobranca($this->txid());
        // expires_in 60 - margem 30 = 30 s de cache.
        $this->travel(31)->seconds();
        SicrediPix::consultarCobranca($this->txid());

        Http::assertSent(fn(Request $r) => str_contains($r->url(), '/cob/') && $r->hasHeader('Authorization', 'Bearer pix-tok-2'));
        Http::assertSentCount(4);
    }

    public function test_401_na_api_renova_token_uma_vez(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::sequence()->push(self::pixTokenResposta('pix-tok-1'))->push(self::pixTokenResposta('pix-tok-2')),
            self::PIX_BASE . '/cob/*' => Http::sequence()
                ->push(['title' => 'Não autorizado', 'status' => 401], 401)
                ->push(self::cobResposta($this->txid(), 'CONCLUIDA')),
        ]);

        $this->assertTrue(SicrediPix::consultarCobranca($this->txid())->concluida());
        Http::assertSent(fn(Request $r) => str_contains($r->url(), '/cob/') && $r->hasHeader('Authorization', 'Bearer pix-tok-2'));
    }

    public function test_mtls_em_todas_as_chamadas_inclusive_token(): void
    {
        $opcoes = [];
        Http::fake(function (Request $r, array $options) use (&$opcoes) {
            $opcoes[$r->url()] = $options;

            return $r->url() === self::PIX_TOKEN
                ? Http::response(self::pixTokenResposta())
                : Http::response(self::cobResposta($this->txid()));
        });

        SicrediPix::consultarCobranca($this->txid());

        $esperado = SicrediPix::opcoesHttp();
        $this->assertSame(SicrediPix::config()->certificado, $esperado['cert']);
        $this->assertSame([SicrediPix::config()->chave, 'senha-da-chave'], $esperado['ssl_key']);

        $this->assertCount(2, $opcoes);
        foreach ($opcoes as $url => $o) {
            $this->assertSame($esperado['cert'], $o['cert'] ?? null, "cert ausente em {$url}");
            $this->assertSame($esperado['ssl_key'], $o['ssl_key'] ?? null, "ssl_key ausente em {$url}");
        }
    }

    public function test_falha_de_autenticacao(): void
    {
        Http::fake([self::PIX_TOKEN => Http::response(['error' => 'invalid_client', 'error_description' => 'Client inválido'], 400)]);

        try {
            SicrediPix::consultarCobranca($this->txid());
            $this->fail('esperava exceção');
        } catch (PixAutenticacaoException $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringContainsString('Client inválido', $e->getMessage());
            $this->assertStringNotContainsString('segredo-cliente', $e->getMessage());
        }
    }

    public function test_resposta_sem_access_token(): void
    {
        Http::fake([self::PIX_TOKEN => Http::response(['foo' => 'bar'])]);

        $this->expectException(PixException::class);
        $this->expectExceptionMessage('sem access_token');
        SicrediPix::consultarCobranca($this->txid());
    }
}
