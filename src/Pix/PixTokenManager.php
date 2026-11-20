<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use VagKaefer\SicrediCobranca\Exceptions\PixAutenticacaoException;
use VagKaefer\SicrediCobranca\Exceptions\PixException;

/**
 * Obtém e guarda em cache o access_token da API Pix (OAuth2 client_credentials com mTLS).
 *
 * O Sicredi avisa que autenticar a cada chamada pode bloquear o cliente temporariamente: o token
 * fica em cache até expires_in menos uma margem, e um lock evita autenticações paralelas.
 */
class PixTokenManager
{
    /** Margem para renovar antes de o token expirar de fato. */
    private const MARGEM_SEGUNDOS = 30;

    public function __construct(
        private readonly PixConfig $config,
        private readonly HttpFactory $http,
        private readonly CacheFactory $cache,
    ) {}

    public function token(): string
    {
        $cache = $this->store();
        $chave = $this->config->chaveCache();

        $token = $cache->get("{$chave}:access");
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $obter = function () use ($cache, $chave): string {
            // Outro processo pode ter renovado enquanto este esperava o lock.
            $token = $cache->get("{$chave}:access");
            if (is_string($token) && $token !== '') {
                return $token;
            }

            return $this->renovar($cache, $chave);
        };

        $store = $cache->getStore();
        if ($store instanceof LockProvider) {
            return $store->lock("{$chave}:lock", 15)->block(15, $obter);
        }

        return $obter();
    }

    /**
     * Descarta o token em cache (ex.: depois de um 401 ou da troca de credenciais).
     */
    public function esquecer(): void
    {
        $this->store()->forget($this->config->chaveCache() . ':access');
    }

    private function renovar(Repository $cache, string $chave): string
    {
        try {
            /** @var Response $response */
            $response = $this->http
                ->withOptions($this->config->opcoesHttp())
                ->withBasicAuth($this->config->clientId, $this->config->clientSecret)
                ->asForm()
                ->acceptJson()
                ->timeout($this->config->timeout)
                ->connectTimeout($this->config->connectTimeout)
                ->post($this->config->tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'scope' => $this->config->escopos,
                ]);
        } catch (ConnectionException|TransferException $e) {
            throw new PixException('Sicredi Pix: falha de conexão na autenticação (confira o certificado, a chave e a senha da chave): ' . $e->getMessage(), 0, null, $e);
        }

        if (! $response->successful()) {
            $e = PixException::daResposta($response, 'autenticar');

            // Credencial ou escopo inválido costuma vir como 400/401; trata tudo como falha de autenticação.
            throw $e instanceof PixAutenticacaoException
                ? $e
                : new PixAutenticacaoException($e->getMessage(), $e->status, $e->corpo, null, $e->correlationId, $e->violacoes, $e->tipo, $e->titulo, $e->detalhe);
        }

        $dados = $response->json();
        if (! is_array($dados) || empty($dados['access_token']) || ! is_string($dados['access_token'])) {
            throw new PixAutenticacaoException('Sicredi Pix: resposta de autenticação sem access_token.', $response->status(), $response->body());
        }

        $token = $dados['access_token'];
        $cache->put("{$chave}:access", $token, max(10, (int) ($dados['expires_in'] ?? 300) - self::MARGEM_SEGUNDOS));

        return $token;
    }

    private function store(): Repository
    {
        return $this->cache->store($this->config->cacheStore);
    }
}
