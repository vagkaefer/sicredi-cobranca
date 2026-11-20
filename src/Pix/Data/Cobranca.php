<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Data;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use VagKaefer\SicrediCobranca\Pix\Enums\StatusCobranca;

/**
 * Cobrança imediata (resposta de PUT/GET/PATCH /cob/{txid} e itens de GET /cob).
 */
final class Cobranca
{
    /**
     * @param list<PixRecebido> $pagamentos Pix recebidos (só quando CONCLUIDA).
     * @param array<string, mixed> $dados Resposta original.
     */
    public function __construct(
        public readonly string $txid,
        public readonly string $status,
        public readonly int $revisao,
        public readonly ?DateTimeImmutable $criacao,
        public readonly ?int $expiracao,
        public readonly float $valor,
        public readonly ?string $chave,
        public readonly ?string $location,
        public readonly ?string $pixCopiaECola,
        public readonly ?string $solicitacaoPagador,
        public readonly array $pagamentos = [],
        public readonly array $dados = [],
    ) {}

    /**
     * @param array<string, mixed> $dados
     */
    public static function fromArray(array $dados): self
    {
        $calendario = is_array($dados['calendario'] ?? null) ? $dados['calendario'] : [];
        $loc = is_array($dados['loc'] ?? null) ? $dados['loc'] : [];

        $pagamentos = [];
        foreach ((array) ($dados['pix'] ?? []) as $p) {
            if (is_array($p)) {
                $pagamentos[] = PixRecebido::fromArray($p);
            }
        }

        return new self(
            txid: (string) ($dados['txid'] ?? ''),
            status: (string) ($dados['status'] ?? ''),
            revisao: (int) ($dados['revisao'] ?? 0),
            criacao: FormataPix::lerData($calendario['criacao'] ?? null),
            expiracao: isset($calendario['expiracao']) && is_numeric($calendario['expiracao']) ? (int) $calendario['expiracao'] : null,
            valor: FormataPix::lerValor($dados['valor'] ?? null),
            chave: FormataPix::lerTexto($dados['chave'] ?? null),
            location: FormataPix::lerTexto($dados['location'] ?? $loc['location'] ?? null),
            pixCopiaECola: FormataPix::lerTexto($dados['pixCopiaECola'] ?? null),
            solicitacaoPagador: FormataPix::lerTexto($dados['solicitacaoPagador'] ?? null),
            pagamentos: $pagamentos,
            dados: $dados,
        );
    }

    /**
     * Status como enum; null quando a API devolver um valor desconhecido.
     */
    public function situacao(): ?StatusCobranca
    {
        return StatusCobranca::tryFrom(strtoupper($this->status));
    }

    public function ativa(): bool
    {
        return $this->situacao() === StatusCobranca::Ativa;
    }

    public function concluida(): bool
    {
        return $this->situacao() === StatusCobranca::Concluida;
    }

    /**
     * Removida pelo recebedor (cancelada) ou pelo PSP.
     */
    public function removida(): bool
    {
        return $this->situacao()?->removida() ?? false;
    }

    /**
     * Momento em que o QR deixa de valer (criação + expiração).
     */
    public function expiraEm(): ?DateTimeImmutable
    {
        if ($this->criacao === null || $this->expiracao === null) {
            return null;
        }

        return $this->criacao->add(new DateInterval('PT' . max(0, $this->expiracao) . 'S'));
    }

    /**
     * Expirada pelo prazo. O status pode continuar ATIVA depois de expirar: use esta verificação
     * para decidir se é preciso gerar outra cobrança.
     */
    public function expirada(?DateTimeInterface $agora = null): bool
    {
        $expira = $this->expiraEm();

        return $expira !== null && $expira <= ($agora ?? new DateTimeImmutable());
    }

    /**
     * Soma dos Pix recebidos nesta cobrança.
     */
    public function valorPago(): float
    {
        $total = 0.0;
        foreach ($this->pagamentos as $p) {
            $total += $p->valor;
        }

        return round($total, 2);
    }
}
