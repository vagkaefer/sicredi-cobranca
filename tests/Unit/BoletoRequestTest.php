<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VagKaefer\SicrediCobranca\Config;
use VagKaefer\SicrediCobranca\Data\BoletoRequest;
use VagKaefer\SicrediCobranca\Data\Desconto;
use VagKaefer\SicrediCobranca\Data\Juros;
use VagKaefer\SicrediCobranca\Data\Multa;
use VagKaefer\SicrediCobranca\Data\Pagador;
use VagKaefer\SicrediCobranca\Enums\Ambiente;
use VagKaefer\SicrediCobranca\Enums\TipoCobranca;
use VagKaefer\SicrediCobranca\Enums\TipoPessoa;
use VagKaefer\SicrediCobranca\Enums\TipoValor;

class BoletoRequestTest extends TestCase
{
    private function pagador(): Pagador
    {
        return new Pagador(
            documento: '12.345.678/0001-95',
            nome: 'EMPRESA COM UM NOME MUITO GRANDE QUE PASSA DOS QUARENTA CARACTERES LTDA',
            endereco: "Rua   das Flores,\n 100",
            cidade: 'Porto Alegre',
            uf: 'rs',
            cep: '90.000-000',
            telefone: '+55 (51) 99999-8888',
            email: 'financeiro@example.com',
        );
    }

    public function test_pagador_normaliza_e_corta_campos(): void
    {
        $dados = $this->pagador()->toArray();

        $this->assertSame('PESSOA_JURIDICA', $dados['tipoPessoa']);
        $this->assertSame('12345678000195', $dados['documento']);
        $this->assertSame(40, mb_strlen($dados['nome']));
        $this->assertSame('Rua das Flores, 100', $dados['endereco']);
        $this->assertSame('RS', $dados['uf']);
        $this->assertSame('90000000', $dados['cep']);
        $this->assertSame('51999998888', $dados['telefone']);
        $this->assertSame('financeiro@example.com', $dados['email']);
    }

    public function test_pagador_descarta_email_longo_e_campos_vazios(): void
    {
        $dados = (new Pagador('123.456.789-09', 'Fulano', email: str_repeat('a', 35) . '@example.com'))->toArray();

        $this->assertSame(TipoPessoa::Fisica->value, $dados['tipoPessoa']);
        $this->assertArrayNotHasKey('email', $dados);
        $this->assertArrayNotHasKey('cep', $dados);
        $this->assertArrayNotHasKey('endereco', $dados);
    }

    public function test_pagador_recusa_documento_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Pagador('123', 'Fulano');
    }

    public function test_hibrido_nao_envia_datas_de_juros_e_multa(): void
    {
        $boleto = new BoletoRequest(
            dataVencimento: '10/12/2026',
            valor: 150.456,
            seuNumero: 'NF12345678901',
            pagador: $this->pagador(),
            idTituloEmpresa: 'CG1',
            mensagens: ['  NFS-e 123  ', '', 'linha 2', 'linha 3', 'linha 4', 'linha 5'],
            juros: new Juros(TipoValor::Percentual, 1, dataInicio: '2026-12-11'),
            multa: new Multa(TipoValor::Percentual, 2, '2026-12-11'),
            validadeAposVencimento: 30,
        );

        $dados = $boleto->toArray('12345');

        $this->assertSame('HIBRIDO', $dados['tipoCobranca']);
        $this->assertSame('DUPLICATA_SERVICO_INDICACAO', $dados['especieDocumento']);
        $this->assertSame('2026-12-10', $dados['dataVencimento']);
        $this->assertSame(150.46, $dados['valor']);
        $this->assertSame('NF12345678', $dados['seuNumero']);
        $this->assertSame(['NFS-e 123', 'linha 2', 'linha 3', 'linha 4'], $dados['mensagens']);
        $this->assertSame('PERCENTUAL', $dados['tipoJuros']);
        $this->assertSame('MENSAL', $dados['tipoJurosPercentual']);
        $this->assertSame(2.0, $dados['multa']);
        $this->assertSame(30, $dados['validadeAposVencimento']);
        $this->assertArrayNotHasKey('dataInicioJuros', $dados);
        $this->assertArrayNotHasKey('dataInicioMulta', $dados);
        $this->assertArrayNotHasKey('nossoNumero', $dados);
    }

    public function test_normal_envia_datas_de_juros_e_multa(): void
    {
        $dados = (new BoletoRequest(
            dataVencimento: new \DateTimeImmutable('2026-12-10'),
            valor: 10,
            seuNumero: 'X1',
            pagador: $this->pagador(),
            tipoCobranca: TipoCobranca::Normal,
            juros: Juros::valorPorDia(0.5),
            multa: new Multa(TipoValor::Valor, 3, '2026-12-11'),
            desconto: (new Desconto())->ate('2026-12-01', 5)->ate('2026-12-05', 2),
        ))->toArray('12345');

        $this->assertSame('2026-12-11', $dados['dataInicioMulta']);
        $this->assertArrayNotHasKey('tipoJurosPercentual', $dados);
        $this->assertSame(5.0, $dados['valorDesconto1']);
        $this->assertSame('2026-12-05', $dados['dataDesconto2']);
    }

    public function test_juros_e_multa_zerados_nao_sao_enviados(): void
    {
        $dados = (new BoletoRequest('2026-12-10', 10, 'X1', $this->pagador(), juros: Juros::percentualAoMes(0), multa: Multa::percentual(0)))->toArray('12345');

        $this->assertArrayNotHasKey('tipoJuros', $dados);
        $this->assertArrayNotHasKey('tipoMulta', $dados);
    }

    public function test_valor_zero_e_recusado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BoletoRequest('2026-12-10', 0.001, 'X1', $this->pagador());
    }

    public function test_config_completa_zeros_e_usa_padroes_do_sandbox(): void
    {
        $config = Config::fromArray(['ambiente' => 'homologacao', 'api_key' => 'k']);

        $this->assertSame(Ambiente::Sandbox, $config->ambiente);
        $this->assertSame('123456789', $config->username());
        $this->assertSame('teste123', $config->codigoAcesso);

        $prod = new Config(Ambiente::Producao, 'k', 'c', 123, 3, 4567);
        $this->assertSame('0123', $prod->cooperativa);
        $this->assertSame('03', $prod->posto);
        $this->assertSame('04567', $prod->codigoBeneficiario);
        $this->assertSame('https://api-parceiro.sicredi.com.br/cobranca/boleto/v1', $prod->ambiente->urlCobranca());
    }

    public function test_config_de_producao_exige_credenciais(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codigo_acesso');
        Config::fromArray(['ambiente' => 'producao', 'api_key' => 'k', 'cooperativa' => '1', 'posto' => '1', 'codigo_beneficiario' => '1']);
    }

    public function test_situacoes_do_boleto_consultado(): void
    {
        $situacao = fn(string $s) => \VagKaefer\SicrediCobranca\Data\Boleto::fromArray(['nossoNumero' => '1', 'situacao' => $s]);

        // Valor real devolvido pela produção para boleto híbrido em aberto.
        $this->assertTrue($situacao('EM CARTEIRA PIX')->emAberto());
        $this->assertTrue($situacao('Em carteira')->emAberto());
        $this->assertTrue($situacao('VENCIDO')->emAberto());
        $this->assertTrue($situacao('LIQUIDADO PIX')->liquidado());
        $this->assertTrue($situacao('Baixado por solicitação')->baixado());
        $this->assertFalse($situacao('LIQUIDADO')->emAberto());
    }
}
