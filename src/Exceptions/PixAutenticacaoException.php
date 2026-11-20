<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Exceptions;

/**
 * HTTP 401/403 na API Pix: credenciais, escopo do token ou certificado mTLS inválidos.
 */
class PixAutenticacaoException extends PixException {}
