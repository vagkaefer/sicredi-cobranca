<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Data;

/**
 * Resposta dos comandos de instrução (202). O comando é processado depois, de forma assíncrona.
 */
final class ComandoResponse
{
    /**
     * @param array<string, mixed> $dados Resposta original.
     */
    public function __construct(
        public readonly ?string $transactionId,
        public readonly ?string $statusComando,
        public readonly ?string $tipoMensagem,
        public readonly ?string $dataMovimento,
        public readonly ?string $nossoNumero,
        public readonly array $dados = [],
    ) {}

    /**
     * @param array<string, mixed> $dados
     */
    public static function fromArray(array $dados): self
    {
        $s = fn(string $k) => isset($dados[$k]) ? (string) $dados[$k] : null;

        return new self($s('transactionId'), $s('statusComando'), $s('tipoMensagem'), $s('dataMovimento'), $s('nossoNumero'), $dados);
    }
}
