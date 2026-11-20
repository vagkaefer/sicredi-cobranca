<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Enums;

/**
 * Natureza da devolução de um Pix recebido.
 *
 * ORIGINAL devolve o valor da transação; RETIRADA, o do Pix Saque/Troco. As naturezas MED_* são
 * usadas no Mecanismo Especial de Devolução.
 */
enum NaturezaDevolucao: string
{
    case Original = 'ORIGINAL';
    case Retirada = 'RETIRADA';
    case MedOperacional = 'MED_OPERACIONAL';
    case MedFraude = 'MED_FRAUDE';
    case MedPixAutomatico = 'MED_PIX_AUTOMATICO';
}
