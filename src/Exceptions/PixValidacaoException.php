<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Exceptions;

/**
 * HTTP 400/422 na API Pix: dados inválidos (veja $violacoes).
 */
class PixValidacaoException extends PixException {}
