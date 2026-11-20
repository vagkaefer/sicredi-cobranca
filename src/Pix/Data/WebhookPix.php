<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Data;

use DateTimeImmutable;

/**
 * Webhook configurado para a chave Pix (GET /webhook/{chave}).
 */
final class WebhookPix
{
    /**
     * @param array<string, mixed> $dados Resposta original.
     */
    public function __construct(
        public readonly string $webhookUrl,
        public readonly ?string $chave,
        public readonly ?DateTimeImmutable $criacao,
        public readonly array $dados = [],
    ) {}

    /**
     * @param array<string, mixed> $dados
     */
    public static function fromArray(array $dados): self
    {
        return new self(
            webhookUrl: (string) ($dados['webhookUrl'] ?? ''),
            chave: FormataPix::lerTexto($dados['chave'] ?? null),
            criacao: FormataPix::lerData($dados['criacao'] ?? null),
            dados: $dados,
        );
    }
}
