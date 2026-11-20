<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests\Feature\Pix;

use Illuminate\Support\Facades\Http;
use VagKaefer\SicrediCobranca\Exceptions\PixAutenticacaoException;
use VagKaefer\SicrediCobranca\Exceptions\PixException;
use VagKaefer\SicrediCobranca\Exceptions\PixLimiteRequisicoesException;
use VagKaefer\SicrediCobranca\Exceptions\PixNaoEncontradoException;
use VagKaefer\SicrediCobranca\Exceptions\PixValidacaoException;
use VagKaefer\SicrediCobranca\Exceptions\SicrediException;
use VagKaefer\SicrediCobranca\Facades\SicrediPix;
use VagKaefer\SicrediCobranca\Pix\Data\CobRequest;
use VagKaefer\SicrediCobranca\Tests\TestCase;

class PixErrosTest extends TestCase
{
    private const TXID = 'CG0000000000000000000000000000F1';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_problem_json_com_violacoes_e_correlation_id(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/cob/*' => Http::response([
                'type' => 'https://pix.bcb.gov.br/api/v2/error/CobOperacaoInvalida',
                'title' => 'Cobrança inválida.',
                'status' => 400,
                'detail' => 'A requisição que busca alterar ou criar uma cobrança não respeita o schema.',
                'correlationId' => 'c0rr-123',
                'violacoes' => [
                    ['razao' => 'O campo cob.calendario.expiracao é igual ou menor que zero.', 'propriedade' => 'cob.calendario.expiracao'],
                ],
            ], 400, ['Content-Type' => 'application/problem+json']),
        ]);

        try {
            SicrediPix::criarCobranca(new CobRequest(10), self::TXID);
            $this->fail('esperava exceção');
        } catch (PixValidacaoException $e) {
            $this->assertInstanceOf(SicrediException::class, $e);
            $this->assertSame(400, $e->status);
            $this->assertSame('c0rr-123', $e->correlationId);
            $this->assertSame('https://pix.bcb.gov.br/api/v2/error/CobOperacaoInvalida', $e->tipo);
            $this->assertSame('Cobrança inválida.', $e->titulo);
            $this->assertSame([['razao' => 'O campo cob.calendario.expiracao é igual ou menor que zero.', 'propriedade' => 'cob.calendario.expiracao']], $e->violacoes);
            $this->assertStringContainsString('criar a cobrança (HTTP 400)', $e->getMessage());
            $this->assertStringContainsString('cob.calendario.expiracao: O campo', $e->getMessage());
            $this->assertStringContainsString('[correlationId: c0rr-123]', $e->getMessage());
        }
    }

    public function test_status_viram_excecoes_tipadas(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/cob/*' => Http::sequence()
                ->push(['title' => 'Cobrança não encontrada.', 'status' => 404, 'correlationId' => 'x1'], 404)
                ->push(['title' => 'Acesso negado', 'status' => 403], 403)
                ->push(['title' => 'Muitas requisições', 'status' => 429], 429)
                ->push('Service Unavailable', 503, ['x-correlation-id' => 'hdr-9']),
        ]);

        try {
            SicrediPix::consultarCobranca(self::TXID);
            $this->fail('esperava exceção');
        } catch (PixNaoEncontradoException $e) {
            $this->assertSame('x1', $e->correlationId);
        }

        try {
            SicrediPix::consultarCobranca(self::TXID);
            $this->fail('esperava exceção');
        } catch (PixAutenticacaoException $e) {
            $this->assertStringContainsString('escopos do token e o certificado', $e->getMessage());
        }

        try {
            SicrediPix::consultarCobranca(self::TXID);
            $this->fail('esperava exceção');
        } catch (PixLimiteRequisicoesException $e) {
            $this->assertSame(429, $e->status);
        }

        try {
            SicrediPix::consultarCobranca(self::TXID);
            $this->fail('esperava exceção');
        } catch (PixException $e) {
            $this->assertSame(PixException::class, $e::class);
            $this->assertSame('hdr-9', $e->correlationId);
            $this->assertStringContainsString('Service Unavailable', $e->getMessage());
        }
    }

    public function test_falha_de_conexao(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/cob/*' => fn() => throw new \Illuminate\Http\Client\ConnectionException('cURL error 58: unable to set private key file'),
        ]);

        try {
            SicrediPix::consultarCobranca(self::TXID);
            $this->fail('esperava exceção');
        } catch (PixException $e) {
            $this->assertSame(0, $e->status);
            $this->assertStringContainsString('falha de conexão ao consultar a cobrança', $e->getMessage());
        }
    }
}
