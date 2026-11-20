<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests\Unit\Pix;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VagKaefer\SicrediCobranca\Pix\Data\Cobranca;
use VagKaefer\SicrediCobranca\Pix\Data\CobRequest;
use VagKaefer\SicrediCobranca\Pix\Data\Devedor;
use VagKaefer\SicrediCobranca\Pix\Data\FormataPix;
use VagKaefer\SicrediCobranca\Pix\Enums\StatusCobranca;
use VagKaefer\SicrediCobranca\Pix\SicrediPix;

class CobRequestTest extends TestCase
{
    public function test_payload_completo(): void
    {
        $cob = new CobRequest(
            valor: 150,
            expiracao: 1800,
            devedor: new Devedor('123.456.789-09', "  Fulano\nde Tal "),
            solicitacaoPagador: str_repeat('a', 200),
            infoAdicionais: ['Pedido' => '987', str_repeat('n', 60) => str_repeat('v', 250)],
        );

        $corpo = $cob->toArray('chave@exemplo.com');

        $this->assertSame(['expiracao' => 1800], $corpo['calendario']);
        $this->assertSame(['cpf' => '12345678909', 'nome' => 'Fulano de Tal'], $corpo['devedor']);
        $this->assertSame(['original' => '150.00'], $corpo['valor']);
        $this->assertSame('chave@exemplo.com', $corpo['chave']);
        $this->assertSame(140, mb_strlen($corpo['solicitacaoPagador']));
        $this->assertSame(['nome' => 'Pedido', 'valor' => '987'], $corpo['infoAdicionais'][0]);
        $this->assertSame(50, mb_strlen($corpo['infoAdicionais'][1]['nome']));
        $this->assertSame(200, mb_strlen($corpo['infoAdicionais'][1]['valor']));
    }

    public function test_payload_minimo_com_cnpj_e_chave_propria(): void
    {
        $cob = new CobRequest('10,5', devedor: new Devedor('12.345.678/0001-95', 'Cliente Ltda'), chave: 'outra-chave');
        $corpo = $cob->toArray('padrao');

        $this->assertSame(['cnpj' => '12345678000195', 'nome' => 'Cliente Ltda'], $corpo['devedor']);
        $this->assertSame('10.50', $corpo['valor']['original']);
        $this->assertSame(3600, $corpo['calendario']['expiracao']);
        $this->assertSame('outra-chave', $corpo['chave']);
        $this->assertArrayNotHasKey('infoAdicionais', $corpo);
        $this->assertArrayNotHasKey('solicitacaoPagador', $corpo);
    }

    public function test_valor_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CobRequest(0);
    }

    public function test_valor_acima_do_limite(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CobRequest('99999999999.00');
    }

    public function test_expiracao_invalida(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CobRequest(10, expiracao: 0);
    }

    public function test_documento_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Devedor('123', 'X');
    }

    public function test_txid_gerado_e_valido(): void
    {
        $txid = SicrediPix::gerarTxid();

        $this->assertSame(32, strlen($txid));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{26,35}$/', $txid);
        $this->assertNotSame($txid, SicrediPix::gerarTxid());
        $this->assertTrue(SicrediPix::txidValido(str_repeat('a', 26)));
        $this->assertFalse(SicrediPix::txidValido(str_repeat('a', 25)));
        $this->assertFalse(SicrediPix::txidValido(str_repeat('a', 30) . '-'));

        $this->expectException(InvalidArgumentException::class);
        SicrediPix::gerarTxid(40);
    }

    public function test_datas_em_utc(): void
    {
        $this->assertSame('2026-11-20T03:00:00Z', FormataPix::dataHoraUtc('2026-11-20'));
        $this->assertSame('2026-11-21T02:59:59Z', FormataPix::dataHoraUtc('20/11/2026 23:59:59'));
        $this->assertSame('2026-11-20T10:00:00Z', FormataPix::dataHoraUtc(new DateTimeImmutable('2026-11-20 10:00', new DateTimeZone('UTC'))));
        $this->assertSame('2026-11-20T12:00:00Z', FormataPix::dataHoraUtc('2026-11-20T09:00:00-03:00'));
    }

    public function test_cobranca_expiracao_e_status(): void
    {
        $cob = Cobranca::fromArray([
            'txid' => 'abc',
            'status' => 'ATIVA',
            'calendario' => ['criacao' => '2026-11-20T13:00:00Z', 'expiracao' => 3600],
            'valor' => ['original' => '1.00'],
        ]);

        $this->assertSame(StatusCobranca::Ativa, $cob->situacao());
        $this->assertSame('2026-11-20T14:00:00+00:00', $cob->expiraEm()?->format(DATE_ATOM));
        $this->assertFalse($cob->expirada(new DateTimeImmutable('2026-11-20T13:59:59Z')));
        $this->assertTrue($cob->expirada(new DateTimeImmutable('2026-11-20T14:00:00Z')));
        $this->assertFalse($cob->removida());

        $this->assertTrue(Cobranca::fromArray(['status' => 'REMOVIDA_PELO_PSP'])->removida());
        $this->assertNull(Cobranca::fromArray(['status' => 'OUTRO'])->situacao());
    }
}
