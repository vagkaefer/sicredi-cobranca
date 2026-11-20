<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Data;

use DateTimeImmutable;

/**
 * Pix recebido (GET /pix, GET /pix/{e2eid}, pagamentos de uma cobrança e callback do webhook).
 */
final class PixRecebido
{
    /**
     * @param list<Devolucao> $devolucoes
     * @param array<string, mixed> $dados Item original.
     */
    public function __construct(
        public readonly string $endToEndId,
        public readonly ?string $txid,
        public readonly float $valor,
        public readonly ?DateTimeImmutable $horario,
        public readonly ?string $chave,
        public readonly ?string $infoPagador,
        public readonly array $devolucoes = [],
        public readonly array $dados = [],
    ) {}

    /**
     * @param array<string, mixed> $dados
     */
    public static function fromArray(array $dados): self
    {
        $devolucoes = [];
        foreach ((array) ($dados['devolucoes'] ?? []) as $d) {
            if (is_array($d)) {
                $devolucoes[] = Devolucao::fromArray($d);
            }
        }

        return new self(
            endToEndId: (string) ($dados['endToEndId'] ?? $dados['e2eid'] ?? ''),
            txid: FormataPix::lerTexto($dados['txid'] ?? null),
            valor: FormataPix::lerValor($dados['valor'] ?? null),
            horario: FormataPix::lerData($dados['horario'] ?? null),
            chave: FormataPix::lerTexto($dados['chave'] ?? null),
            infoPagador: FormataPix::lerTexto($dados['infoPagador'] ?? null),
            devolucoes: $devolucoes,
            dados: $dados,
        );
    }

    /**
     * Soma das devoluções já concluídas (status DEVOLVIDO).
     */
    public function valorDevolvido(): float
    {
        $total = 0.0;
        foreach ($this->devolucoes as $d) {
            if ($d->devolvido()) {
                $total += $d->valor;
            }
        }

        return round($total, 2);
    }
}
