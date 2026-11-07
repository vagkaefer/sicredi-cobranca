<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Enums;

enum TipoCobranca: string
{
    /** Boleto tradicional. */
    case Normal = 'NORMAL';

    /** Boleto com QR Code Pix. Exige a modalidade híbrida liberada pela cooperativa. */
    case Hibrido = 'HIBRIDO';
}
