<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Exceptions;

/**
 * HTTP 400/422: dados inválidos ou regra de negócio não atendida.
 */
class ValidacaoException extends SicrediException {}
