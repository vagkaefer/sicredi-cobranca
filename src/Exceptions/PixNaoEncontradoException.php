<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Exceptions;

/**
 * HTTP 404 na API Pix: cobrança, Pix, devolução ou webhook inexistente.
 */
class PixNaoEncontradoException extends PixException {}
