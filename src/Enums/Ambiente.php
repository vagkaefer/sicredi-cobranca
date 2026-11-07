<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Enums;

use InvalidArgumentException;

enum Ambiente: string
{
    case Sandbox = 'sandbox';
    case Producao = 'producao';

    private const HOST = 'https://api-parceiro.sicredi.com.br';

    /**
     * Aceita também os apelidos mais comuns ("homologacao", "prod", "production"...).
     */
    public static function parse(self|string $valor): self
    {
        if ($valor instanceof self) {
            return $valor;
        }

        return match (strtolower(trim($valor))) {
            'sandbox', 'sb', 'homologacao', 'homologação', 'homolog', 'teste', 'testing' => self::Sandbox,
            'producao', 'produção', 'prod', 'production' => self::Producao,
            default => throw new InvalidArgumentException("Ambiente Sicredi inválido: \"{$valor}\". Use \"sandbox\" ou \"producao\"."),
        };
    }

    private function prefixo(): string
    {
        return $this === self::Sandbox ? '/sb' : '';
    }

    public function urlAutenticacao(): string
    {
        return self::HOST . $this->prefixo() . '/auth/openapi/token';
    }

    /**
     * Base da API de boletos, sem barra no final.
     */
    public function urlCobranca(): string
    {
        return self::HOST . $this->prefixo() . '/cobranca/boleto/v1';
    }

    public function urlCobrancaV2(): string
    {
        return self::HOST . $this->prefixo() . '/cobranca/boleto/v2';
    }
}
