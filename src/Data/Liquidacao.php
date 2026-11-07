<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Data;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Item da consulta de boletos liquidados por dia.
 */
final class Liquidacao
{
    /**
     * @param array<string, mixed> $dados Item original.
     */
    public function __construct(
        public readonly string $nossoNumero,
        public readonly ?string $seuNumero,
        public readonly ?DateTimeImmutable $dataPagamento,
        public readonly float $valor,
        public readonly float $valorLiquidado,
        public readonly float $juros,
        public readonly float $multa,
        public readonly float $desconto,
        public readonly float $abatimento,
        public readonly ?string $tipoLiquidacao,
        public readonly array $dados = [],
    ) {}

    /**
     * @param array<string, mixed> $dados
     */
    public static function fromArray(array $dados): self
    {
        return new self(
            nossoNumero: (string) ($dados['nossoNumero'] ?? ''),
            seuNumero: isset($dados['seuNumero']) ? (string) $dados['seuNumero'] : null,
            dataPagamento: self::parseData($dados['dataPagamento'] ?? null),
            valor: (float) ($dados['valor'] ?? 0),
            valorLiquidado: (float) ($dados['valorLiquidado'] ?? $dados['valor'] ?? 0),
            juros: (float) ($dados['jurosLiquido'] ?? 0),
            multa: (float) ($dados['multaLiquida'] ?? 0),
            desconto: (float) ($dados['descontoLiquido'] ?? 0),
            abatimento: (float) ($dados['abatimentoLiquido'] ?? 0),
            tipoLiquidacao: isset($dados['tipoLiquidacao']) ? (string) $dados['tipoLiquidacao'] : null,
            dados: $dados,
        );
    }

    /**
     * Aceita os formatos vistos na API: "2026-11-05 06:32:05.4", ISO 8601 com offset e "Y-m-d".
     * Sem fuso informado, assume America/Sao_Paulo.
     */
    public static function parseData(mixed $valor): ?DateTimeImmutable
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(trim($valor), new DateTimeZone('America/Sao_Paulo'));
        } catch (Throwable) {
            return null;
        }
    }
}
