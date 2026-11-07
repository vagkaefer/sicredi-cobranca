<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Enums;

/**
 * Forma de cálculo de juros, multa e desconto.
 */
enum TipoValor: string
{
    case Valor = 'VALOR';
    case Percentual = 'PERCENTUAL';
}
