<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix;

use InvalidArgumentException;
use RuntimeException;
use VagKaefer\SicrediCobranca\Pix\Enums\AmbientePix;

/**
 * Credenciais e parâmetros de uma conta na API Pix do Sicredi.
 *
 * Toda chamada (inclusive a do token) usa mTLS: certificado e chave privada em PEM. Os dois podem ser
 * informados por caminho ou pelo conteúdo PEM; no segundo caso o conteúdo é gravado num arquivo
 * temporário com permissão 600, porque o Guzzle só aceita caminho.
 */
final class PixConfig
{
    public const ESCOPOS_PADRAO = 'cob.write cob.read pix.read pix.write webhook.read webhook.write';

    public readonly AmbientePix $ambiente;
    public readonly string $baseUrl;
    public readonly string $tokenUrl;
    public readonly string $escopos;

    /** Caminho do certificado PEM (gravado em arquivo temporário quando veio o conteúdo). */
    public readonly string $certificado;

    /** Caminho da chave privada PEM (gravado em arquivo temporário quando veio o conteúdo). */
    public readonly string $chave;

    /** Conteúdo do certificado, lido uma vez (usado no cache do token e na impressão digital). */
    private readonly string $conteudoCertificado;

    public function __construct(
        AmbientePix|string $ambiente,
        public readonly string $clientId,
        #[\SensitiveParameter]
        public readonly string $clientSecret,
        string $certificado,
        #[\SensitiveParameter]
        string $chave,
        #[\SensitiveParameter]
        public readonly ?string $chaveSenha = null,
        public readonly ?string $chavePix = null,
        ?string $baseUrl = null,
        ?string $tokenUrl = null,
        string $escopos = self::ESCOPOS_PADRAO,
        public readonly int $timeout = 30,
        public readonly int $connectTimeout = 10,
        public readonly ?string $cacheStore = null,
        public readonly ?string $webhookToken = null,
        #[\SensitiveParameter]
        public readonly ?string $certificadoSenha = null,
        public readonly ?string $diretorioTemporario = null,
    ) {
        $this->ambiente = AmbientePix::parse($ambiente);
        $this->escopos = trim((string) preg_replace('/[\s,]+/', ' ', $escopos)) ?: self::ESCOPOS_PADRAO;

        $faltando = array_keys(array_filter([
            'client_id' => trim($clientId),
            'client_secret' => trim($clientSecret),
            'certificado' => trim($certificado),
            'chave' => trim($chave),
        ], fn(string $v) => $v === ''));

        $baseUrl = self::url($baseUrl) ?? $this->ambiente->urlPadrao();
        $tokenUrl = self::url($tokenUrl) ?? $this->ambiente->tokenPadrao();

        // O host de homologação não é público: sem ele não há para onde mandar.
        if ($baseUrl === null) {
            $faltando[] = 'base_url';
        }
        if ($tokenUrl === null) {
            $faltando[] = 'token_url';
        }

        if ($faltando !== []) {
            $extra = $this->ambiente === AmbientePix::Homologacao && ($baseUrl === null || $tokenUrl === null)
                ? ' Em homologação, base_url e token_url são obrigatórios: o host é informado pelo Sicredi ao liberar o acesso.'
                : '';

            throw new InvalidArgumentException('Configuração Pix Sicredi incompleta: ' . implode(', ', $faltando) . '.' . $extra);
        }

        $this->baseUrl = (string) $baseUrl;
        $this->tokenUrl = (string) $tokenUrl;
        $this->certificado = $this->arquivoPem($certificado, 'certificado');
        $this->chave = $this->arquivoPem($chave, 'chave');

        $this->conteudoCertificado = self::validarConteudo($this->certificado, $this->chave);

        if ($this->chavePix !== null && mb_strlen($this->chavePix) > 77) {
            throw new InvalidArgumentException('Chave Pix com mais de 77 caracteres.');
        }
    }

    /**
     * Monta a partir do array de configuração (mesmas chaves da seção "pix" de config/sicredi-cobranca.php).
     *
     * @param array<string, mixed> $dados
     */
    public static function fromArray(array $dados): self
    {
        $texto = fn(string $chave): string => trim((string) ($dados[$chave] ?? ''));
        $opcional = fn(string $chave): ?string => $texto($chave) === '' ? null : $texto($chave);

        $webhook = is_array($dados['webhook'] ?? null) ? $dados['webhook'] : [];
        $webhookToken = trim((string) ($dados['webhook_token'] ?? $webhook['token'] ?? ''));

        return new self(
            ambiente: $texto('ambiente') ?: AmbientePix::Homologacao->value,
            clientId: $texto('client_id'),
            clientSecret: $texto('client_secret'),
            certificado: $texto('certificado'),
            chave: $texto('chave'),
            // Senha não leva trim: espaços podem fazer parte dela.
            chaveSenha: ($dados['chave_senha'] ?? null) ?: null,
            chavePix: $opcional('chave_pix'),
            baseUrl: $opcional('base_url'),
            tokenUrl: $opcional('token_url'),
            escopos: $texto('escopos') ?: self::ESCOPOS_PADRAO,
            timeout: (int) ($dados['timeout'] ?? 30) ?: 30,
            connectTimeout: (int) ($dados['connect_timeout'] ?? 10) ?: 10,
            cacheStore: $opcional('cache_store'),
            webhookToken: $webhookToken === '' ? null : $webhookToken,
            certificadoSenha: ($dados['certificado_senha'] ?? null) ?: null,
            diretorioTemporario: $opcional('diretorio_temporario'),
        );
    }

    /**
     * Opções do Guzzle para o mTLS, usadas em todas as chamadas (inclusive a do token).
     *
     * @return array{cert: string|array{0: string, 1: string}, ssl_key: string|array{0: string, 1: string}}
     */
    public function opcoesHttp(): array
    {
        return [
            'cert' => $this->certificadoSenha !== null ? [$this->certificado, $this->certificadoSenha] : $this->certificado,
            'ssl_key' => $this->chaveSenha !== null ? [$this->chave, $this->chaveSenha] : $this->chave,
        ];
    }

    /**
     * Chave Pix do recebedor; lança exceção clara quando a operação precisa dela e não foi configurada.
     */
    public function chavePixObrigatoria(): string
    {
        if ($this->chavePix === null || $this->chavePix === '') {
            throw new InvalidArgumentException('Configuração Pix Sicredi incompleta: chave_pix (a chave Pix do recebedor).');
        }

        return $this->chavePix;
    }

    /**
     * Impressão digital (thumbprint) do certificado: hash do DER, em hexadecimal maiúsculo.
     * Compare com a exibida no Portal do Desenvolvedor quando a API responder 403.
     */
    public function impressaoDigital(string $algoritmo = 'sha1'): ?string
    {
        if (! preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $this->conteudoCertificado, $m)) {
            return null;
        }

        $der = base64_decode((string) preg_replace('/\s+/', '', $m[1]), true);

        return $der === false ? null : strtoupper(hash($algoritmo, $der));
    }

    /**
     * Chave única por conta, usada no cache do token.
     * Inclui um hash das credenciais, dos escopos e do certificado para que trocar qualquer um
     * (ex.: renovação do certificado) invalide o token antigo.
     */
    public function chaveCache(): string
    {
        $hash = substr(hash('sha256', $this->clientSecret . '|' . $this->escopos . '|' . $this->tokenUrl . '|' . $this->conteudoCertificado), 0, 12);

        return "sicredi-pix:{$this->ambiente->value}:{$this->clientId}:{$hash}";
    }

    private static function url(?string $url): ?string
    {
        $url = rtrim(trim((string) $url), '/');

        return $url === '' ? null : $url;
    }

    /**
     * Devolve um caminho para o PEM. Conteúdo PEM vira arquivo temporário (permissão 600), reaproveitado
     * enquanto o conteúdo não mudar.
     */
    private function arquivoPem(string $valor, string $tipo): string
    {
        $valor = trim($valor);

        if (! str_starts_with($valor, '-----BEGIN')) {
            if (! is_file($valor) || ! is_readable($valor)) {
                throw new InvalidArgumentException("Pix Sicredi: arquivo do {$tipo} não encontrado ou sem permissão de leitura: \"{$valor}\".");
            }

            return $valor;
        }

        $diretorio = rtrim($this->diretorioTemporario ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sicredi-pix', DIRECTORY_SEPARATOR);
        if (! is_dir($diretorio) && ! @mkdir($diretorio, 0o700, true) && ! is_dir($diretorio)) {
            throw new RuntimeException("Pix Sicredi: não foi possível criar o diretório temporário \"{$diretorio}\".");
        }

        $conteudo = $valor . "\n";
        $arquivo = $diretorio . DIRECTORY_SEPARATOR . $tipo . '-' . substr(hash('sha256', $conteudo), 0, 24) . '.pem';

        if (is_file($arquivo) && @file_get_contents($arquivo) === $conteudo) {
            return $arquivo;
        }

        // tempnam cria o arquivo com permissão 600; o rename deixa a troca atômica.
        $temporario = @tempnam($diretorio, $tipo);
        if ($temporario === false || @file_put_contents($temporario, $conteudo) === false) {
            throw new RuntimeException("Pix Sicredi: não foi possível gravar o {$tipo} em \"{$diretorio}\".");
        }
        @chmod($temporario, 0o600);

        if (! @rename($temporario, $arquivo)) {
            @unlink($temporario);
            throw new RuntimeException("Pix Sicredi: não foi possível gravar o {$tipo} em \"{$arquivo}\".");
        }

        return $arquivo;
    }

    /**
     * Detecta os erros mais comuns antes de chegar ao Sicredi (que só responde 403 ou erro de TLS).
     * Devolve o conteúdo do certificado.
     */
    private static function validarConteudo(string $certificado, string $chave): string
    {
        $cert = (string) @file_get_contents($certificado);
        $key = (string) @file_get_contents($chave);

        if (! str_contains($cert, '-----BEGIN')) {
            throw new InvalidArgumentException('Pix Sicredi: o certificado não está em PEM (provavelmente DER). Converta com: openssl x509 -inform der -in certificado.cer -out certificado.pem');
        }
        if (! str_contains($key, '-----BEGIN')) {
            throw new InvalidArgumentException('Pix Sicredi: a chave privada não está em PEM (provavelmente DER). Converta com: openssl rsa -inform der -in chave.der -out chave.pem');
        }
        if (! str_contains($cert, 'CERTIFICATE-----') && str_contains($cert, 'PRIVATE KEY-----')) {
            throw new InvalidArgumentException('Pix Sicredi: o arquivo do certificado contém uma chave privada. Certificado e chave estão trocados?');
        }
        if (! str_contains($cert, 'CERTIFICATE-----')) {
            throw new InvalidArgumentException('Pix Sicredi: o arquivo do certificado não contém um certificado PEM (BEGIN CERTIFICATE).');
        }
        if (! str_contains($key, 'PRIVATE KEY-----')) {
            throw new InvalidArgumentException(str_contains($key, 'CERTIFICATE-----')
                ? 'Pix Sicredi: o arquivo da chave contém um certificado. Certificado e chave estão trocados?'
                : 'Pix Sicredi: o arquivo da chave não contém uma chave privada PEM (BEGIN PRIVATE KEY).');
        }

        return $cert;
    }
}
