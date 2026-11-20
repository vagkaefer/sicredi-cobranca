<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Enums;

use InvalidArgumentException;

/**
 * Ambiente da API Pix do Sicredi.
 *
 * O host de homologação não é público (o Sicredi informa ao liberar o acesso), então em homologação
 * as URLs da API e do token precisam vir da configuração.
 */
enum AmbientePix: string
{
    case Homologacao = 'homologacao';
    case Producao = 'producao';

    public const URL_PRODUCAO = 'https://api-pix.sicredi.com.br/api/v2';
    public const TOKEN_PRODUCAO = 'https://api-pix.sicredi.com.br/oauth/token';

    /**
     * Aceita também os apelidos mais comuns ("sandbox", "prod", "production"...).
     */
    public static function parse(self|string $valor): self
    {
        if ($valor instanceof self) {
            return $valor;
        }

        return match (strtolower(trim($valor))) {
            'homologacao', 'homologação', 'homolog', 'hml', 'sandbox', 'sb', 'teste', 'testing' => self::Homologacao,
            'producao', 'produção', 'prod', 'production' => self::Producao,
            default => throw new InvalidArgumentException("Ambiente Pix inválido: \"{$valor}\". Use \"homologacao\" ou \"producao\"."),
        };
    }

    /**
     * Base padrão da API (null quando não há padrão público).
     */
    public function urlPadrao(): ?string
    {
        return $this === self::Producao ? self::URL_PRODUCAO : null;
    }

    public function tokenPadrao(): ?string
    {
        return $this === self::Producao ? self::TOKEN_PRODUCAO : null;
    }
}
