<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Exceptions;

/**
 * HTTP 429: limite de requisições por segundo excedido.
 */
class LimiteRequisicoesException extends SicrediException {}
