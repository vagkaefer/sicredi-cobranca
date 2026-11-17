<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Data;

use DateTimeImmutable;

/**
 * Boleto consultado (GET /boletos?nossoNumero=...).
 */
final class Boleto
{
    /**
     * @param array<string, mixed> $dados Resposta original.
     */
    public function __construct(
        public readonly string $nossoNumero,
        public readonly ?string $seuNumero,
        public readonly ?string $linhaDigitavel,
        public readonly ?string $codigoBarras,
        public readonly string $situacao,
        public readonly ?string $dataVencimento,
        public readonly ?float $valorNominal,
        public readonly ?string $txid,
        public readonly ?string $qrCode,
        public readonly ?DateTimeImmutable $dataLiquidacao,
        public readonly ?float $valorLiquidado,
        public readonly array $dados = [],
    ) {}

    /**
     * @param array<string, mixed> $dados
     */
    public static function fromArray(array $dados): self
    {
        $liquidacao = is_array($dados['dadosLiquidacao'] ?? null) ? $dados['dadosLiquidacao'] : [];

        return new self(
            nossoNumero: (string) ($dados['nossoNumero'] ?? ''),
            seuNumero: self::texto($dados['seuNumero'] ?? null),
            linhaDigitavel: self::texto($dados['linhaDigitavel'] ?? null),
            codigoBarras: self::texto($dados['codigoBarras'] ?? null),
            situacao: (string) ($dados['situacao'] ?? ''),
            dataVencimento: self::texto($dados['dataVencimento'] ?? null),
            valorNominal: isset($dados['valorNominal']) ? (float) $dados['valorNominal'] : null,
            txid: self::texto($dados['txId'] ?? $dados['txid'] ?? null),
            qrCode: self::texto($dados['codigoQrCode'] ?? $dados['qrCode'] ?? null),
            dataLiquidacao: Liquidacao::parseData($liquidacao['data'] ?? null),
            valorLiquidado: isset($liquidacao['valor']) ? (float) $liquidacao['valor'] : null,
            dados: $dados,
        );
    }

    /**
     * Situação sem acentos, em maiúsculas e com espaços simples ("BAIXADO POR SOLICITACAO").
     */
    public function situacaoNormalizada(): string
    {
        return self::normalizar($this->situacao);
    }

    public function liquidado(): bool
    {
        return str_starts_with($this->situacaoNormalizada(), 'LIQUIDADO');
    }

    public function baixado(): bool
    {
        return str_starts_with($this->situacaoNormalizada(), 'BAIXADO');
    }

    /**
     * Disponível para pagamento. Em produção o híbrido vem como "EM CARTEIRA PIX".
     */
    public function emAberto(): bool
    {
        $situacao = $this->situacaoNormalizada();

        return str_starts_with($situacao, 'EM CARTEIRA') || str_starts_with($situacao, 'VENCIDO');
    }

    public static function normalizar(string $situacao): string
    {
        $s = strtr(mb_strtoupper(trim($situacao)), [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E',
            'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C', '_' => ' ',
        ]);

        return (string) preg_replace('/\s+/', ' ', $s);
    }

    private static function texto(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
