<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Data;

/**
 * Resposta do registro de boleto (201).
 */
final class BoletoCriado
{
    /**
     * @param array<string, mixed> $dados Resposta original.
     */
    public function __construct(
        public readonly string $nossoNumero,
        public readonly string $linhaDigitavel,
        public readonly string $codigoBarras,
        public readonly ?string $txid,
        public readonly ?string $qrCode,
        public readonly ?string $cooperativa,
        public readonly ?string $posto,
        public readonly array $dados = [],
    ) {}

    /**
     * @param array<string, mixed> $dados
     */
    public static function fromArray(array $dados): self
    {
        return new self(
            nossoNumero: (string) ($dados['nossoNumero'] ?? ''),
            linhaDigitavel: (string) ($dados['linhaDigitavel'] ?? ''),
            codigoBarras: (string) ($dados['codigoBarras'] ?? ''),
            txid: self::opcional($dados['txid'] ?? null),
            // O sandbox devolve o copia e cola com quebras de linha no final.
            qrCode: self::opcional($dados['qrCode'] ?? null),
            cooperativa: self::opcional($dados['cooperativa'] ?? null),
            posto: self::opcional($dados['posto'] ?? null),
            dados: $dados,
        );
    }

    /**
     * Pix copia e cola (o mesmo conteúdo do QR Code).
     */
    public function pixCopiaECola(): ?string
    {
        return $this->qrCode;
    }

    /**
     * Falso quando um pedido HIBRIDO voltou sem QR Code (modalidade não liberada,
     * ou datas de juros/multa enviadas).
     */
    public function temPix(): bool
    {
        return $this->qrCode !== null;
    }

    private static function opcional(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
