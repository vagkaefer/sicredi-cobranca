<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use VagKaefer\SicrediCobranca\Config;
use VagKaefer\SicrediCobranca\Data\BoletoRequest;
use VagKaefer\SicrediCobranca\Data\Liquidacao;
use VagKaefer\SicrediCobranca\Data\Pagador;
use VagKaefer\SicrediCobranca\Enums\Ambiente;
use VagKaefer\SicrediCobranca\Exceptions\AutenticacaoException;
use VagKaefer\SicrediCobranca\Exceptions\LimiteRequisicoesException;
use VagKaefer\SicrediCobranca\Exceptions\SicrediException;
use VagKaefer\SicrediCobranca\Exceptions\ValidacaoException;
use VagKaefer\SicrediCobranca\Facades\SicrediCobranca;
use VagKaefer\SicrediCobranca\Tests\TestCase;

class ClienteTest extends TestCase
{
    private function boleto(): BoletoRequest
    {
        return new BoletoRequest('2026-12-10', 12.34, 'NF1', new Pagador('12345678909', 'JOAO DA SILVA'), idTituloEmpresa: 'CG1');
    }

    public function test_autentica_cria_boleto_e_reaproveita_token(): void
    {
        Http::fake([
            self::AUTH_SB => Http::response(self::tokenResposta()),
            self::BASE_SB . '/boletos' => Http::response(self::boletoCriadoResposta(), 201),
        ]);

        $criado = SicrediCobranca::criarBoleto($this->boleto());
        SicrediCobranca::criarBoleto($this->boleto());

        $this->assertSame('211001292', $criado->nossoNumero);
        $this->assertTrue($criado->temPix());
        $this->assertStringEndsWith('630441AC', (string) $criado->pixCopiaECola());

        Http::assertSentCount(3);
        Http::assertSent(function (Request $r) {
            return $r->url() === self::AUTH_SB
                && $r->hasHeader('x-api-key', 'chave-teste')
                && $r->hasHeader('context', 'COBRANCA')
                && $r['grant_type'] === 'password'
                && $r['username'] === '123456789'
                && $r['password'] === 'teste123'
                && $r['scope'] === 'cobranca';
        });
        Http::assertSent(function (Request $r) {
            return $r->url() === self::BASE_SB . '/boletos'
                && $r->method() === 'POST'
                && $r->hasHeader('Authorization', 'Bearer tok-1')
                && $r->hasHeader('cooperativa', '6789')
                && $r->hasHeader('posto', '03')
                && $r['codigoBeneficiario'] === '12345'
                && $r['tipoCobranca'] === 'HIBRIDO'
                && $r['pagador']['tipoPessoa'] === 'PESSOA_FISICA';
        });
    }

    public function test_usa_refresh_token_quando_access_expira(): void
    {
        Http::fake([
            self::AUTH_SB => Http::sequence()
                ->push(self::tokenResposta('tok-1', 'ref-1'))
                ->push(self::tokenResposta('tok-2', 'ref-2')),
            self::BASE_SB . '/boletos*' => Http::response(['nossoNumero' => '1', 'situacao' => 'EM CARTEIRA']),
        ]);

        SicrediCobranca::consultarBoleto('1');
        Cache::forget(SicrediCobranca::config()->chaveCache() . ':access');
        SicrediCobranca::consultarBoleto('1');

        Http::assertSent(fn(Request $r) => $r->url() === self::AUTH_SB && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'ref-1');
        Http::assertSent(fn(Request $r) => str_contains($r->url(), '/boletos?') && $r->hasHeader('Authorization', 'Bearer tok-2'));
    }

    public function test_refaz_login_quando_refresh_falha(): void
    {
        Http::fake([
            self::AUTH_SB => Http::sequence()
                ->push(self::tokenResposta('tok-1', 'ref-1'))
                ->push(['error' => 'invalid_grant'], 400)
                ->push(self::tokenResposta('tok-3', 'ref-3')),
            self::BASE_SB . '/boletos*' => Http::response(['nossoNumero' => '1', 'situacao' => 'EM CARTEIRA']),
        ]);

        SicrediCobranca::consultarBoleto('1');
        Cache::forget(SicrediCobranca::config()->chaveCache() . ':access');
        SicrediCobranca::consultarBoleto('1');

        Http::assertSent(fn(Request $r) => str_contains($r->url(), '/boletos?') && $r->hasHeader('Authorization', 'Bearer tok-3'));
    }

    public function test_401_na_api_renova_token_uma_vez(): void
    {
        Http::fake([
            self::AUTH_SB => Http::sequence()->push(self::tokenResposta('tok-1'))->push(self::tokenResposta('tok-2', 'ref-2'))->push(self::tokenResposta('tok-3')),
            self::BASE_SB . '/boletos*' => Http::sequence()
                ->push(['message' => 'token expirado'], 401)
                ->push(['nossoNumero' => '1', 'situacao' => 'LIQUIDADO']),
        ]);

        $boleto = SicrediCobranca::consultarBoleto('1');

        $this->assertTrue($boleto->liquidado());
    }

    public function test_falha_de_login_vira_autenticacao_exception(): void
    {
        Http::fake([self::AUTH_SB => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid user credentials'], 400)]);

        $this->expectException(AutenticacaoException::class);
        $this->expectExceptionMessage('Invalid user credentials');

        SicrediCobranca::criarBoleto($this->boleto());
    }

    public function test_erros_viram_excecoes_tipadas(): void
    {
        Http::fake([
            self::AUTH_SB => Http::response(self::tokenResposta()),
            self::BASE_SB . '/boletos' => Http::sequence()
                ->push(['message' => 'Beneficiário sem modalidade híbrida', 'erros' => [['campo' => 'tipoCobranca', 'mensagem' => 'não habilitado']]], 422)
                ->push([], 429)
                ->push('Gateway Timeout', 504),
        ]);

        try {
            SicrediCobranca::criarBoleto($this->boleto());
            $this->fail('esperava exceção');
        } catch (ValidacaoException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('tipoCobranca: não habilitado', $e->getMessage());
        }

        try {
            SicrediCobranca::criarBoleto($this->boleto());
            $this->fail('esperava exceção');
        } catch (LimiteRequisicoesException $e) {
            $this->assertSame(429, $e->status);
        }

        $this->expectException(SicrediException::class);
        $this->expectExceptionMessage('HTTP 504');
        SicrediCobranca::criarBoleto($this->boleto());
    }

    public function test_pdf_retorna_bytes(): void
    {
        Http::fake([
            self::AUTH_SB => Http::response(self::tokenResposta()),
            self::BASE_SB . '/boletos/pdf*' => Http::response('%PDF-1.4 conteudo', 201, ['Content-Type' => 'application/octet-stream']),
        ]);

        $this->assertStringStartsWith('%PDF', SicrediCobranca::pdf('7489.11211 50039'));
        Http::assertSent(fn(Request $r) => str_contains($r->url(), 'linhaDigitavel=74891121150039'));
    }

    public function test_pdf_invalido_lanca_excecao(): void
    {
        Http::fake([
            self::AUTH_SB => Http::response(self::tokenResposta()),
            self::BASE_SB . '/boletos/pdf*' => Http::response('<html>erro</html>', 200),
        ]);

        $this->expectException(SicrediException::class);
        SicrediCobranca::pdf('123');
    }

    public function test_liquidados_percorre_paginas(): void
    {
        $item = fn(string $n) => ['nossoNumero' => $n, 'dataPagamento' => '2026-11-05 06:32:05.4', 'valor' => 195, 'valorLiquidado' => 201.6, 'jurosLiquido' => 2.7, 'multaLiquida' => 3.9, 'tipoLiquidacao' => 'PIX'];

        Http::fake([
            self::AUTH_SB => Http::response(self::tokenResposta()),
            self::BASE_SB . '/boletos/liquidados/dia*' => Http::sequence()
                ->push(['items' => [$item('1'), $item('2')], 'hasNext' => true])
                ->push(['items' => [$item('3')], 'hasNext' => false]),
        ]);

        $itens = iterator_to_array(SicrediCobranca::liquidadosPorDia('2026-11-05'), false);

        $this->assertCount(3, $itens);
        $this->assertContainsOnlyInstancesOf(Liquidacao::class, $itens);
        $this->assertSame(201.6, $itens[0]->valorLiquidado);
        $this->assertSame('2026-11-05 06:32', $itens[0]->dataPagamento?->format('Y-m-d H:i'));
        Http::assertSent(fn(Request $r) => str_contains($r->url(), 'dia=05%2F11%2F2026') && str_contains($r->url(), 'pagina=1'));
    }

    public function test_baixa_envia_patch_com_codigo_beneficiario(): void
    {
        Http::fake([
            self::AUTH_SB => Http::response(self::tokenResposta()),
            self::BASE_SB . '/boletos/211001292/baixa' => Http::response(['transactionId' => 't1', 'statusComando' => 'MOVIMENTO_ENVIADO', 'tipoMensagem' => 'BAIXA'], 202),
        ]);

        $resposta = SicrediCobranca::baixar('211001292');

        $this->assertSame('MOVIMENTO_ENVIADO', $resposta->statusComando);
        Http::assertSent(fn(Request $r) => $r->method() === 'PATCH' && $r->hasHeader('codigoBeneficiario', '12345') && $r->body() === '{}');
    }

    public function test_resolver_de_config_e_usando(): void
    {
        Http::fake([
            'https://api-parceiro.sicredi.com.br/auth/openapi/token' => Http::response(self::tokenResposta()),
            'https://api-parceiro.sicredi.com.br/cobranca/boleto/v1/boletos*' => Http::response(['nossoNumero' => '1', 'situacao' => 'EM CARTEIRA']),
        ]);

        SicrediCobranca::resolverConfigUsing(fn() => [
            'ambiente' => 'producao', 'api_key' => 'k-prod', 'codigo_acesso' => 'senha',
            'cooperativa' => '0101', 'posto' => '02', 'codigo_beneficiario' => '00123',
        ]);

        $this->assertTrue(SicrediCobranca::consultarBoleto('1')->emAberto());
        Http::assertSent(fn(Request $r) => $r->url() === 'https://api-parceiro.sicredi.com.br/auth/openapi/token' && $r['username'] === '001230101');

        $sandbox = SicrediCobranca::usando(Config::sandbox('k-sb'));
        $this->assertSame(Ambiente::Sandbox, $sandbox->config()->ambiente);
    }

    public function test_webhook_so_em_producao(): void
    {
        $this->expectException(\LogicException::class);
        SicrediCobranca::listarWebhooks();
    }
}
