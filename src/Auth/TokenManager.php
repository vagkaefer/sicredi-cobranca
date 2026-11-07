<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Auth;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use VagKaefer\SicrediCobranca\Config;
use VagKaefer\SicrediCobranca\Exceptions\AutenticacaoException;
use VagKaefer\SicrediCobranca\Exceptions\SicrediException;

/**
 * Obtém e guarda em cache o access_token (OAuth2 password grant + refresh_token).
 */
class TokenManager
{
    /** Margem para renovar antes de o token expirar de fato. */
    private const MARGEM_SEGUNDOS = 30;

    public function __construct(
        private readonly Config $config,
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
        $chave = $this->config->chaveCache();
        $this->store()->forget("{$chave}:access");
        $this->store()->forget("{$chave}:refresh");
    }

    private function renovar(Repository $cache, string $chave): string
    {
        $refresh = $cache->get("{$chave}:refresh");

        if (is_string($refresh) && $refresh !== '') {
            try {
                return $this->guardar($cache, $chave, $this->solicitar([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh,
                ]));
            } catch (SicrediException) {
                // Refresh expirado ou revogado: autentica de novo com usuário e senha.
                $cache->forget("{$chave}:refresh");
            }
        }

        return $this->guardar($cache, $chave, $this->solicitar([
            'grant_type' => 'password',
            'username' => $this->config->username(),
            'password' => $this->config->codigoAcesso,
            'scope' => 'cobranca',
        ]));
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function solicitar(array $form): array
    {
        try {
            /** @var Response $response */
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->timeout($this->config->timeout)
                ->connectTimeout($this->config->connectTimeout)
                ->withHeaders([
                    'x-api-key' => $this->config->apiKey,
                    'context' => 'COBRANCA',
                ])
                ->post($this->config->ambiente->urlAutenticacao(), $form);
        } catch (ConnectionException $e) {
            throw new SicrediException('Sicredi: falha de conexão na autenticação: ' . $e->getMessage(), 0, null, $e);
        }

        if (! $response->successful()) {
            $e = SicrediException::daResposta($response, 'autenticar');

            // A autenticação responde 400 para usuário/senha inválidos; trata tudo como falha de autenticação.
            throw $e instanceof AutenticacaoException ? $e : new AutenticacaoException($e->getMessage(), $e->status, $e->corpo);
        }

        $dados = $response->json();
        if (! is_array($dados) || empty($dados['access_token'])) {
            throw new AutenticacaoException('Sicredi: resposta de autenticação sem access_token.', $response->status(), $response->body());
        }

        return $dados;
    }

    /**
     * @param array<string, mixed> $dados
     */
    private function guardar(Repository $cache, string $chave, array $dados): string
    {
        $token = (string) $dados['access_token'];

        $cache->put("{$chave}:access", $token, $this->ttl($dados['expires_in'] ?? 300));

        if (! empty($dados['refresh_token'])) {
            $cache->put("{$chave}:refresh", (string) $dados['refresh_token'], $this->ttl($dados['refresh_expires_in'] ?? 1800));
        }

        return $token;
    }

    private function ttl(mixed $segundos): int
    {
        return max(10, (int) $segundos - self::MARGEM_SEGUNDOS);
    }

    private function store(): Repository
    {
        return $this->cache->store($this->config->cacheStore);
    }
}
