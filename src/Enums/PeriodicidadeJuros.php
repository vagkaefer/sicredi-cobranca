<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Enums;

/**
 * Obrigatório quando os juros são percentuais (campo tipoJurosPercentual).
 */
enum PeriodicidadeJuros: string
{
    case Diario = 'DIARIO';
    case Mensal = 'MENSAL';
}
