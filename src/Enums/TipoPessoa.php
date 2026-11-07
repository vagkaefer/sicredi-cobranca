<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Enums;

enum TipoPessoa: string
{
    case Fisica = 'PESSOA_FISICA';
    case Juridica = 'PESSOA_JURIDICA';

    /**
     * Deduz pelo tamanho do documento: 11 dígitos = CPF, o resto = CNPJ.
     */
    public static function doDocumento(string $documento): self
    {
        return strlen((string) preg_replace('/\D/', '', $documento)) === 11 ? self::Fisica : self::Juridica;
    }
}
