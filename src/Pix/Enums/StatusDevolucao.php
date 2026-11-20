<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Enums;

enum StatusDevolucao: string
{
    case EmProcessamento = 'EM_PROCESSAMENTO';
    case Devolvido = 'DEVOLVIDO';
    case NaoRealizado = 'NAO_REALIZADO';
}
