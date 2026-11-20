<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Data;

use DateTimeImmutable;
use VagKaefer\SicrediCobranca\Pix\Enums\StatusDevolucao;

/**
 * Devolução de um Pix recebido (PUT/GET /pix/{e2eid}/devolucao/{id}).
 */
final class Devolucao
{
    /**
     * @param array<string, mixed> $dados Resposta original.
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $rtrId,
        public readonly float $valor,
        public readonly string $status,
        public readonly ?string $natureza,
        public readonly ?string $descricao,
        public readonly ?string $motivo,
        public readonly ?DateTimeImmutable $solicitacao,
        public readonly ?DateTimeImmutable $liquidacao,
        public readonly array $dados = [],
    ) {}

    /**
     * @param array<string, mixed> $dados
     */
    public static function fromArray(array $dados): self
    {
        $horario = is_array($dados['horario'] ?? null) ? $dados['horario'] : [];

        return new self(
            id: (string) ($dados['id'] ?? ''),
            rtrId: FormataPix::lerTexto($dados['rtrId'] ?? null),
            valor: FormataPix::lerValor($dados['valor'] ?? null),
            status: (string) ($dados['status'] ?? ''),
            natureza: FormataPix::lerTexto($dados['natureza'] ?? null),
            descricao: FormataPix::lerTexto($dados['descricao'] ?? null),
            motivo: FormataPix::lerTexto($dados['motivo'] ?? null),
            solicitacao: FormataPix::lerData($horario['solicitacao'] ?? null),
            liquidacao: FormataPix::lerData($horario['liquidacao'] ?? null),
            dados: $dados,
        );
    }

    /**
     * Status como enum; null quando a API devolver um valor desconhecido.
     */
    public function situacao(): ?StatusDevolucao
    {
        return StatusDevolucao::tryFrom(strtoupper($this->status));
    }

    public function emProcessamento(): bool
    {
        return $this->situacao() === StatusDevolucao::EmProcessamento;
    }

    public function devolvido(): bool
    {
        return $this->situacao() === StatusDevolucao::Devolvido;
    }

    public function naoRealizado(): bool
    {
        return $this->situacao() === StatusDevolucao::NaoRealizado;
    }
}
