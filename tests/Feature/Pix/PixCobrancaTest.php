<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests\Feature\Pix;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use VagKaefer\SicrediCobranca\Facades\SicrediPix;
use VagKaefer\SicrediCobranca\Pix\Data\Cobranca;
use VagKaefer\SicrediCobranca\Pix\Data\CobRequest;
use VagKaefer\SicrediCobranca\Pix\Data\Devedor;
use VagKaefer\SicrediCobranca\Pix\Enums\StatusCobranca;
use VagKaefer\SicrediCobranca\Tests\TestCase;

class PixCobrancaTest extends TestCase
{
    private const TXID = 'CG0000000000000000000000000000F1';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function cob(): CobRequest
    {
        return new CobRequest(
            valor: 150.00,
            expiracao: 3600,
            devedor: new Devedor('12345678909', 'Fulano de Tal'),
            solicitacaoPagador: 'Fatura 123',
            infoAdicionais: ['Pedido' => '987'],
        );
    }

    public function test_cria_cobranca_com_txid_gerado(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/cob/*' => fn(Request $r) => Http::response(self::cobResposta(basename($r->url())), 201),
        ]);

        $cob = SicrediPix::criarCobranca($this->cob());

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{32}$/', $cob->txid);
        $this->assertTrue($cob->ativa());
        $this->assertSame(150.0, $cob->valor);
        $this->assertStringStartsWith('000201', (string) $cob->pixCopiaECola);
        $this->assertSame('pix-h.sicredi.com.br/qr/v2/cob/abc', $cob->location);
        $this->assertSame('2026-11-20 14:00', $cob->expiraEm()?->format('Y-m-d H:i'));

        Http::assertSent(function (Request $r) use ($cob) {
            return $r->method() === 'PUT'
                && $r->url() === self::PIX_BASE . '/cob/' . $cob->txid
                && $r->hasHeader('Authorization', 'Bearer pix-tok-1')
                && $r['calendario'] === ['expiracao' => 3600]
                && $r['devedor'] === ['cpf' => '12345678909', 'nome' => 'Fulano de Tal']
                && $r['valor'] === ['original' => '150.00']
                && $r['chave'] === self::PIX_CHAVE
                && $r['solicitacaoPagador'] === 'Fatura 123'
                && $r['infoAdicionais'] === [['nome' => 'Pedido', 'valor' => '987']];
        });
    }

    public function test_cria_cobranca_com_txid_informado(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/cob/' . self::TXID => Http::response(self::cobResposta(self::TXID), 201),
        ]);

        $this->assertSame(self::TXID, SicrediPix::criarCobranca($this->cob(), self::TXID)->txid);
    }

    public function test_txid_invalido_nao_chama_a_api(): void
    {
        Http::fake();

        try {
            SicrediPix::criarCobranca($this->cob(), 'curto-demais');
            $this->fail('esperava exceção');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('txid inválido', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_sem_chave_pix_configurada(): void
    {
        config()->set('sicredi-cobranca.pix.chave_pix', null);
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('chave_pix');
        SicrediPix::criarCobranca($this->cob(), self::TXID);
    }

    public function test_cancelar_envia_so_o_status(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/cob/' . self::TXID => Http::response(self::cobResposta(self::TXID, 'REMOVIDA_PELO_USUARIO_RECEBEDOR', ['revisao' => 1])),
        ]);

        $cob = SicrediPix::cancelarCobranca(self::TXID);

        $this->assertTrue($cob->removida());
        $this->assertSame(StatusCobranca::RemovidaPeloUsuarioRecebedor, $cob->situacao());
        $this->assertSame(1, $cob->revisao);
        Http::assertSent(fn(Request $r) => $r->method() === 'PATCH' && $r->body() === '{"status":"REMOVIDA_PELO_USUARIO_RECEBEDOR"}');
    }

    public function test_revisar_e_consultar_com_pagamento(): void
    {
        $paga = self::cobResposta(self::TXID, 'CONCLUIDA', ['pix' => [[
            'endToEndId' => 'E12345678202611201300abcdefghijk',
            'txid' => self::TXID,
            'valor' => '150.00',
            'horario' => '2026-11-20T13:05:00.000Z',
            'infoPagador' => 'obrigado',
        ]]]);

        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/cob/' . self::TXID . '*' => Http::sequence()
                ->push(self::cobResposta(self::TXID, 'ATIVA', ['revisao' => 1, 'valor' => ['original' => '20.00']]))
                ->push($paga),
        ]);

        $revisada = SicrediPix::revisarCobranca(self::TXID, ['valor' => ['original' => '20.00']]);
        $this->assertSame(20.0, $revisada->valor);
        Http::assertSent(fn(Request $r) => $r->method() === 'PATCH' && $r['valor'] === ['original' => '20.00']);

        $consulta = SicrediPix::consultarCobranca(self::TXID, 1);
        $this->assertTrue($consulta->concluida());
        $this->assertCount(1, $consulta->pagamentos);
        $this->assertSame('E12345678202611201300abcdefghijk', $consulta->pagamentos[0]->endToEndId);
        $this->assertSame(150.0, $consulta->valorPago());
        Http::assertSent(fn(Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/cob/' . self::TXID . '?revisao=1'));
    }

    public function test_listar_cobrancas_paginado(): void
    {
        $pagina = fn(int $atual, array $txids) => [
            'parametros' => ['inicio' => 'x', 'fim' => 'y', 'paginacao' => ['paginaAtual' => $atual, 'itensPorPagina' => 2, 'quantidadeDePaginas' => 2, 'quantidadeTotalDeItens' => 3]],
            'cobs' => array_map(fn(string $t) => self::cobResposta($t), $txids),
        ];

        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/cob?*' => Http::sequence()
                ->push($pagina(0, [str_repeat('a', 26), str_repeat('b', 26)]))
                ->push($pagina(1, [str_repeat('c', 26)])),
        ]);

        $cobs = iterator_to_array(SicrediPix::listarCobrancas('2026-11-01', '2026-11-20 23:59:59', ['status' => 'ATIVA', 'cnpj' => '12.345.678/0001-95', 'itensPorPagina' => 2]), false);

        $this->assertCount(3, $cobs);
        $this->assertContainsOnlyInstancesOf(Cobranca::class, $cobs);
        Http::assertSent(fn(Request $r) => str_contains($r->url(), '/cob?')
            && str_contains($r->url(), 'inicio=2026-11-01T03%3A00%3A00Z')
            && str_contains($r->url(), 'fim=2026-11-21T02%3A59%3A59Z')
            && str_contains($r->url(), 'status=ATIVA')
            && str_contains($r->url(), 'cnpj=12345678000195')
            && str_contains($r->url(), 'paginacao.itensPorPagina=2')
            && str_contains($r->url(), 'paginacao.paginaAtual=1'));
        Http::assertSentCount(3);
    }
}
