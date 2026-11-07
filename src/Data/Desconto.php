<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Data;

use DateTimeInterface;
use InvalidArgumentException;
use VagKaefer\SicrediCobranca\Enums\TipoValor;

/**
 * Até 3 descontos escalonados por data.
 */
final class Desconto
{
    /** @var list<array{valor: float, data: DateTimeInterface|string}> */
    private array $faixas = [];

    public function __construct(public readonly TipoValor $tipo = TipoValor::Valor) {}

    public function ate(DateTimeInterface|string $data, float $valor): self
    {
        if (count($this->faixas) >= 3) {
            throw new InvalidArgumentException('O Sicredi aceita no máximo 3 faixas de desconto.');
        }

        $copia = clone $this;
        $copia->faixas[] = ['valor' => $valor, 'data' => $data];

        return $copia;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $dados = ['tipoDesconto' => $this->tipo->value];

        foreach ($this->faixas as $i => $faixa) {
            $n = $i + 1;
            $dados["valorDesconto{$n}"] = Formata::valor($faixa['valor']);
            $dados["dataDesconto{$n}"] = Formata::data($faixa['data']);
        }

        return $dados;
    }
}
