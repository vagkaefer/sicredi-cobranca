<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Data;

use DateTimeInterface;
use VagKaefer\SicrediCobranca\Enums\PeriodicidadeJuros;
use VagKaefer\SicrediCobranca\Enums\TipoValor;

final class Juros
{
    /**
     * @param float $valor Valor em reais (TipoValor::Valor) ou percentual (TipoValor::Percentual).
     * @param DateTimeInterface|string|null $dataInicio Ignorada em boleto híbrido (ver BoletoRequest).
     */
    public function __construct(
        public readonly TipoValor $tipo,
        public readonly float $valor,
        public readonly ?PeriodicidadeJuros $periodicidade = null,
        public readonly DateTimeInterface|string|null $dataInicio = null,
    ) {}

    public static function percentualAoMes(float $percentual): self
    {
        return new self(TipoValor::Percentual, $percentual, PeriodicidadeJuros::Mensal);
    }

    public static function percentualAoDia(float $percentual): self
    {
        return new self(TipoValor::Percentual, $percentual, PeriodicidadeJuros::Diario);
    }

    public static function valorPorDia(float $valor): self
    {
        return new self(TipoValor::Valor, $valor);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $comData): array
    {
        $dados = [
            'tipoJuros' => $this->tipo->value,
            'juros' => Formata::valor($this->valor),
        ];

        if ($this->tipo === TipoValor::Percentual) {
            // Sem a periodicidade, a API aplica um padrão inesperado.
            $dados['tipoJurosPercentual'] = ($this->periodicidade ?? PeriodicidadeJuros::Mensal)->value;
        }

        if ($comData && $this->dataInicio !== null) {
            $dados['dataInicioJuros'] = Formata::data($this->dataInicio);
        }

        return $dados;
    }
}
