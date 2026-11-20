<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Enums;

/**
 * Status da cobrança imediata (padrão API Pix do Banco Central).
 */
enum StatusCobranca: string
{
    case Ativa = 'ATIVA';
    case Concluida = 'CONCLUIDA';
    case RemovidaPeloUsuarioRecebedor = 'REMOVIDA_PELO_USUARIO_RECEBEDOR';
    case RemovidaPeloPsp = 'REMOVIDA_PELO_PSP';

    public function removida(): bool
    {
        return $this === self::RemovidaPeloUsuarioRecebedor || $this === self::RemovidaPeloPsp;
    }
}
