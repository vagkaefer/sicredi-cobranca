<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca;

use InvalidArgumentException;
use VagKaefer\SicrediCobranca\Enums\Ambiente;

/**
 * Credenciais e parâmetros de uma conta de cobrança Sicredi.
 */
final class Config
{
    /** Valores de teste publicados na documentação do sandbox. */
    public const SANDBOX_COOPERATIVA = '6789';
    public const SANDBOX_POSTO = '03';
    public const SANDBOX_CODIGO_BENEFICIARIO = '12345';
    public const SANDBOX_CODIGO_ACESSO = 'teste123';

    public readonly string $cooperativa;
    public readonly string $posto;
    public readonly string $codigoBeneficiario;

    public function __construct(
        public readonly Ambiente $ambiente,
        public readonly string $apiKey,
        public readonly string $codigoAcesso,
        string|int $cooperativa,
        string|int $posto,
        string|int $codigoBeneficiario,
        public readonly int $timeout = 30,
        public readonly int $connectTimeout = 10,
        public readonly ?string $cacheStore = null,
        public readonly ?string $webhookToken = null,
    ) {
        // Códigos sempre como string com zeros à esquerda: número perde o zero e a API recusa.
        $this->cooperativa = self::digitos($cooperativa, 4);
        $this->posto = self::digitos($posto, 2);
        $this->codigoBeneficiario = self::digitos($codigoBeneficiario, 5);

        $faltando = array_keys(array_filter([
            'api_key' => $this->apiKey,
            'codigo_acesso' => $this->codigoAcesso,
            'cooperativa' => $this->cooperativa,
            'posto' => $this->posto,
            'codigo_beneficiario' => $this->codigoBeneficiario,
        ], fn(string $v) => $v === ''));

        if ($faltando !== []) {
            throw new InvalidArgumentException('Configuração Sicredi incompleta: ' . implode(', ', $faltando) . '.');
        }
    }

    /**
     * Monta a partir do array de configuração (mesmas chaves de config/sicredi-cobranca.php).
     * No sandbox, cooperativa/posto/beneficiário/código de acesso vazios usam os valores de teste.
     *
     * @param array<string, mixed> $dados
     */
    public static function fromArray(array $dados): self
    {
        $ambiente = Ambiente::parse((string) ($dados['ambiente'] ?? 'sandbox'));
        $sandbox = $ambiente === Ambiente::Sandbox;

        $valor = function (string $chave, string $padraoSandbox = '') use ($dados, $sandbox): string {
            $v = trim((string) ($dados[$chave] ?? ''));

            return $v === '' && $sandbox ? $padraoSandbox : $v;
        };

        $webhook = is_array($dados['webhook'] ?? null) ? $dados['webhook'] : [];

        return new self(
            ambiente: $ambiente,
            apiKey: $valor('api_key'),
            codigoAcesso: $valor('codigo_acesso', self::SANDBOX_CODIGO_ACESSO),
            cooperativa: $valor('cooperativa', self::SANDBOX_COOPERATIVA),
            posto: $valor('posto', self::SANDBOX_POSTO),
            codigoBeneficiario: $valor('codigo_beneficiario', self::SANDBOX_CODIGO_BENEFICIARIO),
            timeout: (int) ($dados['timeout'] ?? 30) ?: 30,
            connectTimeout: (int) ($dados['connect_timeout'] ?? 10) ?: 10,
            cacheStore: ($dados['cache_store'] ?? null) ?: null,
            webhookToken: ($dados['webhook_token'] ?? $webhook['token'] ?? null) ?: null,
        );
    }

    /**
     * Credenciais de teste do sandbox; só a x-api-key é necessária.
     */
    public static function sandbox(string $apiKey): self
    {
        return new self(
            Ambiente::Sandbox,
            $apiKey,
            self::SANDBOX_CODIGO_ACESSO,
            self::SANDBOX_COOPERATIVA,
            self::SANDBOX_POSTO,
            self::SANDBOX_CODIGO_BENEFICIARIO,
        );
    }

    /**
     * Usuário do password grant: código do beneficiário (5) + cooperativa (4).
     */
    public function username(): string
    {
        return $this->codigoBeneficiario . $this->cooperativa;
    }

    /**
     * Chave única por conta, usada no cache do token.
     * Inclui um hash das credenciais para trocar de código de acesso invalidar o token antigo.
     */
    public function chaveCache(): string
    {
        $hash = substr(hash('sha256', $this->apiKey . '|' . $this->codigoAcesso), 0, 12);

        return "sicredi-cobranca:{$this->ambiente->value}:{$this->username()}:{$hash}";
    }

    private static function digitos(string|int $valor, int $tamanho): string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return '';
        }

        // O posto pode ser alfanumérico; só completa com zeros quando é numérico.
        return ctype_digit($valor) ? str_pad($valor, $tamanho, '0', STR_PAD_LEFT) : $valor;
    }
}
