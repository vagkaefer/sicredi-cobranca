<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests\Feature\Pix;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use VagKaefer\SicrediCobranca\Facades\SicrediPix;
use VagKaefer\SicrediCobranca\Pix\Data\PixRecebido;
use VagKaefer\SicrediCobranca\Pix\Enums\NaturezaDevolucao;
use VagKaefer\SicrediCobranca\Pix\Enums\StatusDevolucao;
use VagKaefer\SicrediCobranca\Tests\TestCase;

class PixRecebidosTest extends TestCase
{
    private const E2E = 'E12345678202611201300abcdefghijk';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * @return array<string, mixed>
     */
    private static function pix(string $e2e, string $valor = '10.00', array $devolucoes = []): array
    {
        return [
            'endToEndId' => $e2e,
            'txid' => str_repeat('T', 32),
            'valor' => $valor,
            'chave' => self::PIX_CHAVE,
            'horario' => '2026-11-20T13:05:00.000Z',
            'infoPagador' => 'Pagamento',
            'devolucoes' => $devolucoes,
        ];
    }

    public function test_pix_recebidos_percorre_paginas_e_converte_datas_para_utc(): void
    {
        $pagina = fn(int $atual, array $itens) => [
            'parametros' => ['paginacao' => ['paginaAtual' => $atual, 'itensPorPagina' => 100, 'quantidadeDePaginas' => 3, 'quantidadeTotalDeItens' => 5]],
            'pix' => $itens,
        ];

        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/pix?*' => Http::sequence()
                ->push($pagina(0, [self::pix('E1'), self::pix('E2')]))
                ->push($pagina(1, [self::pix('E3'), self::pix('E4')]))
                ->push($pagina(2, [self::pix('E5', '7.50')])),
        ]);

        $inicio = new DateTimeImmutable('2026-11-19 06:00', new DateTimeZone('America/Sao_Paulo'));
        $itens = iterator_to_array(SicrediPix::pixRecebidos($inicio, '2026-11-20 06:00', ['txIdPresente' => true, 'devolucaoPresente' => false, 'cpf' => '123.456.789-09']), false);

        $this->assertCount(5, $itens);
        $this->assertContainsOnlyInstancesOf(PixRecebido::class, $itens);
        $this->assertSame(7.5, $itens[4]->valor);
        $this->assertSame('2026-11-20T13:05:00+00:00', $itens[0]->horario?->format(DATE_ATOM));

        Http::assertSentCount(4);
        Http::assertSent(fn(Request $r) => str_contains($r->url(), '/pix?')
            && str_contains($r->url(), 'inicio=2026-11-19T09%3A00%3A00Z')
            && str_contains($r->url(), 'fim=2026-11-20T09%3A00%3A00Z')
            && str_contains($r->url(), 'txIdPresente=true')
            && str_contains($r->url(), 'devolucaoPresente=false')
            && str_contains($r->url(), 'cpf=12345678909')
            && str_contains($r->url(), 'paginacao.paginaAtual=2'));
    }

    public function test_pagina_unica_sem_paginacao(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/pix?*' => Http::response(['pix' => []]),
        ]);

        $this->assertSame([], iterator_to_array(SicrediPix::pixRecebidos('2026-11-20', '2026-11-21')));
        Http::assertSentCount(2);
    }

    public function test_consultar_pix_com_devolucoes(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/pix/' . self::E2E => Http::response(self::pix(self::E2E, '150.00', [
                ['id' => 'D1', 'rtrId' => 'D12345678202611201400abcdefghijk', 'valor' => '50.00', 'status' => 'DEVOLVIDO', 'horario' => ['solicitacao' => '2026-11-20T14:00:00Z', 'liquidacao' => '2026-11-20T14:00:05Z']],
                ['id' => 'D2', 'valor' => '10.00', 'status' => 'EM_PROCESSAMENTO', 'horario' => ['solicitacao' => '2026-11-20T15:00:00Z']],
            ])),
        ]);

        $pix = SicrediPix::consultarPix(self::E2E);

        $this->assertSame(self::E2E, $pix->endToEndId);
        $this->assertCount(2, $pix->devolucoes);
        $this->assertSame(50.0, $pix->valorDevolvido());
        $this->assertTrue($pix->devolucoes[1]->emProcessamento());
    }

    public function test_e2eid_invalido(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        SicrediPix::consultarPix('E123');
    }

    public function test_devolver(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/pix/' . self::E2E . '/devolucao/DEV42' => Http::response([
                'id' => 'DEV42',
                'rtrId' => 'D12345678202611201400abcdefghijk',
                'valor' => '149.90',
                'natureza' => 'ORIGINAL',
                'descricao' => 'Desistência',
                'horario' => ['solicitacao' => '2026-11-20T14:00:00.000Z'],
                'status' => 'EM_PROCESSAMENTO',
            ], 201),
        ]);

        $dev = SicrediPix::devolver(self::E2E, 'DEV42', 149.9, descricao: "Desistência\n");

        $this->assertSame(StatusDevolucao::EmProcessamento, $dev->situacao());
        $this->assertSame(149.9, $dev->valor);
        $this->assertSame('D12345678202611201400abcdefghijk', $dev->rtrId);
        $this->assertNull($dev->liquidacao);

        Http::assertSent(fn(Request $r) => $r->method() === 'PUT'
            && $r->url() === self::PIX_BASE . '/pix/' . self::E2E . '/devolucao/DEV42'
            && $r->data() === ['valor' => '149.90', 'natureza' => 'ORIGINAL', 'descricao' => 'Desistência']);
    }

    public function test_devolver_med_e_consultar(): void
    {
        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            self::PIX_BASE . '/pix/' . self::E2E . '/devolucao/D1' => Http::sequence()
                ->push(['id' => 'D1', 'valor' => '5.00', 'status' => 'EM_PROCESSAMENTO', 'natureza' => 'MED_FRAUDE'], 201)
                ->push(['id' => 'D1', 'valor' => '5.00', 'status' => 'NAO_REALIZADO', 'motivo' => 'Saldo insuficiente']),
        ]);

        SicrediPix::devolver(self::E2E, 'D1', '5', NaturezaDevolucao::MedFraude);
        Http::assertSent(fn(Request $r) => $r->method() === 'PUT' && $r->data() === ['valor' => '5.00', 'natureza' => 'MED_FRAUDE']);

        $dev = SicrediPix::consultarDevolucao(self::E2E, 'D1');
        $this->assertTrue($dev->naoRealizado());
        $this->assertSame('Saldo insuficiente', $dev->motivo);
    }

    public function test_devolver_valida_id_e_natureza(): void
    {
        Http::fake();

        try {
            SicrediPix::devolver(self::E2E, 'id-com-hifen', 1);
            $this->fail('esperava exceção');
        } catch (InvalidArgumentException) {
        }

        try {
            SicrediPix::devolver(self::E2E, 'D1', 1, 'QUALQUER');
            $this->fail('esperava exceção');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Natureza', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_webhook_configurar_consultar_remover(): void
    {
        $url = self::PIX_BASE . '/webhook/' . self::PIX_CHAVE;

        Http::fake([
            self::PIX_TOKEN => Http::response(self::pixTokenResposta()),
            $url => Http::sequence()
                ->push('', 204)
                ->push(['webhookUrl' => 'https://app.exemplo.com/sicredi/pix/webhook?token=x', 'chave' => self::PIX_CHAVE, 'criacao' => '2026-11-20T13:00:00Z'])
                ->push('', 204),
        ]);

        SicrediPix::configurarWebhook('https://app.exemplo.com/sicredi/pix/webhook?token=x');
        $webhook = SicrediPix::consultarWebhook();
        SicrediPix::removerWebhook();

        $this->assertSame('https://app.exemplo.com/sicredi/pix/webhook?token=x', $webhook->webhookUrl);
        Http::assertSent(fn(Request $r) => $r->method() === 'PUT' && $r->url() === $url && $r->data() === ['webhookUrl' => 'https://app.exemplo.com/sicredi/pix/webhook?token=x']);
        Http::assertSent(fn(Request $r) => $r->method() === 'DELETE' && $r->url() === $url);
    }

    public function test_webhook_exige_https(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        SicrediPix::configurarWebhook('http://app.exemplo.com/sicredi/pix/webhook');
    }
}
