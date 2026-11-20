<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Exceptions;

/**
 * HTTP 429 na API Pix: limite de requisições excedido.
 */
class PixLimiteRequisicoesException extends PixException {}
