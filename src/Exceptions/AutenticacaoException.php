<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Exceptions;

/**
 * HTTP 401/403: x-api-key, código de acesso, cooperativa ou beneficiário inválidos.
 */
class AutenticacaoException extends SicrediException {}
