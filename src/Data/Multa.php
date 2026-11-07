<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Data;

use DateTimeInterface;
use VagKaefer\SicrediCobranca\Enums\TipoValor;

final class Multa
{
    /**
     * @param DateTimeInterface|string|null $dataInicio Ignorada em boleto híbrido (ver BoletoRequest).
     */
    public function __construct(
        public readonly TipoValor $tipo,
        public readonly float $valor,
        public readonly DateTimeInterface|string|null $dataInicio = null,
    ) {}

    public static function percentual(float $percentual): self
    {
        return new self(TipoValor::Percentual, $percentual);
    }

    public static function valor(float $valor): self
    {
        return new self(TipoValor::Valor, $valor);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $comData): array
    {
        $dados = [
            'tipoMulta' => $this->tipo->value,
            'multa' => Formata::valor($this->valor),
        ];

        if ($comData && $this->dataInicio !== null) {
            $dados['dataInicioMulta'] = Formata::data($this->dataInicio);
        }

        return $dados;
    }
}
