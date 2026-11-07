<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void resolverConfigUsing(\Closure $resolver)
 * @method static \VagKaefer\SicrediCobranca\SicrediCobranca usando(\VagKaefer\SicrediCobranca\Config $config)
 * @method static \VagKaefer\SicrediCobranca\Config config()
 * @method static \VagKaefer\SicrediCobranca\Auth\TokenManager tokens(?\VagKaefer\SicrediCobranca\Config $config = null)
 * @method static \VagKaefer\SicrediCobranca\Data\BoletoCriado criarBoleto(\VagKaefer\SicrediCobranca\Data\BoletoRequest $boleto)
 * @method static \VagKaefer\SicrediCobranca\Data\Boleto consultarBoleto(string $nossoNumero)
 * @method static string pdf(string $linhaDigitavel)
 * @method static list<array<string, mixed>> cadastrados(?string $idTituloEmpresa = null, ?string $seuNumero = null)
 * @method static \Generator<int, \VagKaefer\SicrediCobranca\Data\Liquidacao> liquidadosPorDia(\DateTimeInterface|string $dia, ?string $cpfCnpjBeneficiarioFinal = null)
 * @method static \VagKaefer\SicrediCobranca\Data\ComandoResponse baixar(string $nossoNumero)
 * @method static \VagKaefer\SicrediCobranca\Data\ComandoResponse alterarVencimento(string $nossoNumero, \DateTimeInterface|string $dataVencimento)
 * @method static \VagKaefer\SicrediCobranca\Data\ComandoResponse alterarJuros(string $nossoNumero, float $valorOuPercentual)
 * @method static \VagKaefer\SicrediCobranca\Data\ComandoResponse alterarDesconto(string $nossoNumero, float $valor1, ?float $valor2 = null, ?float $valor3 = null)
 * @method static \VagKaefer\SicrediCobranca\Data\ComandoResponse alterarSeuNumero(string $nossoNumero, string $seuNumero)
 * @method static \VagKaefer\SicrediCobranca\Data\ComandoResponse concederAbatimento(string $nossoNumero, float $valor)
 * @method static \VagKaefer\SicrediCobranca\Data\ComandoResponse cancelarAbatimento(string $nossoNumero)
 * @method static \VagKaefer\SicrediCobranca\Data\ComandoResponse comando(string $nossoNumero, string $acao, array<string, mixed> $corpo = [])
 * @method static array<string, mixed> registrarWebhook(string $url, list<string> $eventos = ['LIQUIDACAO'], array<string, mixed> $extras = [])
 * @method static array<mixed> listarWebhooks()
 * @method static array<mixed> atualizarWebhook(string $idContrato, array<string, mixed> $dados)
 *
 * @see \VagKaefer\SicrediCobranca\SicrediCobranca
 */
class SicrediCobranca extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \VagKaefer\SicrediCobranca\SicrediCobranca::class;
    }
}
