<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix;

use Closure;
use DateTimeInterface;
use Generator;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use VagKaefer\SicrediCobranca\Exceptions\PixException;
use VagKaefer\SicrediCobranca\Pix\Data\Cobranca;
use VagKaefer\SicrediCobranca\Pix\Data\CobRequest;
use VagKaefer\SicrediCobranca\Pix\Data\Devolucao;
use VagKaefer\SicrediCobranca\Pix\Data\FormataPix;
use VagKaefer\SicrediCobranca\Pix\Data\PixRecebido;
use VagKaefer\SicrediCobranca\Pix\Data\WebhookPix;
use VagKaefer\SicrediCobranca\Pix\Enums\NaturezaDevolucao;
use VagKaefer\SicrediCobranca\Pix\Enums\StatusCobranca;

/**
 * Cliente da API Pix do Sicredi (cobrança imediata, Pix recebidos, devolução e webhook),
 * no padrão da API Pix do Banco Central.
 *
 * @experimental Ainda não validado em homologação; pode mudar numa versão menor.
 */
class SicrediPix
{
    /** Proteção contra laço infinito na paginação. */
    private const MAX_PAGINAS = 1000;

    private const ALFABETO_TXID = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /** @var Closure(): PixConfig */
    private Closure $resolver;

    /** @var (Closure(): ?string)|null */
    private ?Closure $resolverTokenWebhook = null;

    /**
     * @param PixConfig|Closure(): PixConfig $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheFactory $cache,
        PixConfig|Closure $config,
    ) {
        $this->resolver = $config instanceof PixConfig ? fn() => $config : $config;
    }

    /**
     * Define de onde vem a configuração (ex.: banco de dados). Chamado a cada operação.
     *
     * @param Closure(): (PixConfig|array<string, mixed>) $resolver
     */
    public function resolverConfigUsing(Closure $resolver): void
    {
        $this->resolver = function () use ($resolver): PixConfig {
            $config = $resolver();

            return $config instanceof PixConfig ? $config : PixConfig::fromArray($config);
        };
    }

    /**
     * Novo cliente com uma configuração fixa (ex.: outra conta ou outro ambiente).
     */
    public function usando(PixConfig $config): self
    {
        $cliente = new self($this->http, $this->cache, $config);
        $cliente->resolverTokenWebhook = $this->resolverTokenWebhook;

        return $cliente;
    }

    /**
     * Define de onde vem o token exigido no webhook (ex.: banco de dados), sem depender da leitura
     * do certificado: o recebimento continua protegido mesmo com a configuração mTLS incompleta.
     *
     * @param Closure(): ?string $resolver
     */
    public function resolverWebhookTokenUsing(Closure $resolver): void
    {
        $this->resolverTokenWebhook = $resolver;
    }

    /**
     * Token do webhook vindo do resolver explícito; null quando não há resolver ou ele não devolve nada.
     */
    public function webhookTokenExplicito(): ?string
    {
        if ($this->resolverTokenWebhook === null) {
            return null;
        }

        $token = trim((string) ($this->resolverTokenWebhook)());

        return $token === '' ? null : $token;
    }

    public function config(): PixConfig
    {
        return ($this->resolver)();
    }

    public function tokens(?PixConfig $config = null): PixTokenManager
    {
        return new PixTokenManager($config ?? $this->config(), $this->http, $this->cache);
    }

    /**
     * Opções de mTLS (cert/ssl_key) aplicadas em todas as chamadas.
     *
     * @return array<string, mixed>
     */
    public function opcoesHttp(): array
    {
        return $this->config()->opcoesHttp();
    }

    /**
     * txid aleatório e válido para a cobrança imediata ([a-zA-Z0-9]{26,35}).
     */
    public static function gerarTxid(int $tamanho = 32): string
    {
        if ($tamanho < 26 || $tamanho > 35) {
            throw new InvalidArgumentException('O txid da cobrança imediata tem de 26 a 35 caracteres.');
        }

        $txid = '';
        $max = strlen(self::ALFABETO_TXID) - 1;
        for ($i = 0; $i < $tamanho; $i++) {
            $txid .= self::ALFABETO_TXID[random_int(0, $max)];
        }

        return $txid;
    }

    public static function txidValido(string $txid): bool
    {
        return preg_match('/^[a-zA-Z0-9]{26,35}$/', $txid) === 1;
    }

    // ---------------------------------------------------------------------
    // Cobrança imediata (cob)
    // ---------------------------------------------------------------------

    /**
     * Cria a cobrança imediata (PUT /cob/{txid}). Sem txid, gera um de 32 caracteres.
     * Guarde o txid antes de chamar: repetir o PUT com o mesmo txid não cria outra cobrança.
     */
    public function criarCobranca(CobRequest $cobranca, ?string $txid = null): Cobranca
    {
        $txid ??= self::gerarTxid();
        $this->validarTxid($txid);

        $config = $this->config();
        $corpo = $cobranca->toArray($cobranca->chave ?? $config->chavePixObrigatoria());

        return Cobranca::fromArray($this->json($this->enviar($config, 'put', '/cob/' . $txid, 'criar a cobrança', json: $corpo)));
    }

    /**
     * Consulta a cobrança. Antes de dar baixa, confira concluida() e os pagamentos.
     */
    public function consultarCobranca(string $txid, ?int $revisao = null): Cobranca
    {
        $this->validarTxid($txid);
        $config = $this->config();

        $query = $revisao !== null ? ['revisao' => (string) $revisao] : [];

        return Cobranca::fromArray($this->json($this->enviar($config, 'get', '/cob/' . $txid, 'consultar a cobrança', query: $query)));
    }

    /**
     * Revisa a cobrança (PATCH /cob/{txid}) com os campos informados, no formato da API
     * (ex.: ['valor' => ['original' => '20.00']]).
     *
     * @param array<string, mixed> $dados
     */
    public function revisarCobranca(string $txid, array $dados): Cobranca
    {
        $this->validarTxid($txid);

        if ($dados === []) {
            throw new InvalidArgumentException('Informe ao menos um campo para revisar a cobrança.');
        }

        $config = $this->config();

        return Cobranca::fromArray($this->json($this->enviar($config, 'patch', '/cob/' . $txid, 'revisar a cobrança', json: $dados)));
    }

    /**
     * Cancela a cobrança: PATCH só com status REMOVIDA_PELO_USUARIO_RECEBEDOR.
     */
    public function cancelarCobranca(string $txid): Cobranca
    {
        $this->validarTxid($txid);
        $config = $this->config();

        return Cobranca::fromArray($this->json($this->enviar($config, 'patch', '/cob/' . $txid, 'cancelar a cobrança', json: [
            'status' => StatusCobranca::RemovidaPeloUsuarioRecebedor->value,
        ])));
    }

    /**
     * Percorre todas as páginas das cobranças criadas no período (GET /cob).
     *
     * @param array<string, scalar|null> $filtros cpf, cnpj, locationPresente, status, itensPorPagina.
     * @return Generator<int, Cobranca>
     */
    public function listarCobrancas(DateTimeInterface|string $inicio, DateTimeInterface|string $fim, array $filtros = []): Generator
    {
        foreach ($this->paginar('/cob', 'listar as cobranças', 'cobs', $inicio, $fim, $filtros) as $item) {
            yield Cobranca::fromArray($item);
        }
    }

    // ---------------------------------------------------------------------
    // Pix recebidos
    // ---------------------------------------------------------------------

    /**
     * Percorre todas as páginas dos Pix recebidos no período (GET /pix).
     * Datas sem fuso são lidas em America/Sao_Paulo e enviadas em UTC (RFC 3339).
     *
     * @param array<string, scalar|null> $filtros txid, cpf, cnpj, txIdPresente, devolucaoPresente, itensPorPagina.
     * @return Generator<int, PixRecebido>
     */
    public function pixRecebidos(DateTimeInterface|string $inicio, DateTimeInterface|string $fim, array $filtros = []): Generator
    {
        foreach ($this->paginar('/pix', 'consultar os Pix recebidos', 'pix', $inicio, $fim, $filtros) as $item) {
            yield PixRecebido::fromArray($item);
        }
    }

    public function consultarPix(string $e2eid): PixRecebido
    {
        $this->validarE2eid($e2eid);
        $config = $this->config();

        return PixRecebido::fromArray($this->json($this->enviar($config, 'get', '/pix/' . $e2eid, 'consultar o Pix')));
    }

    // ---------------------------------------------------------------------
    // Devolução
    // ---------------------------------------------------------------------

    /**
     * Solicita a devolução de um Pix recebido (PUT /pix/{e2eid}/devolucao/{id}).
     *
     * O id ([a-zA-Z0-9]{1,35}) é seu e identifica a devolução: repetir com o mesmo id não devolve
     * duas vezes. Guarde-o antes de chamar. O status inicial costuma ser EM_PROCESSAMENTO; acompanhe
     * com consultarDevolucao().
     */
    public function devolver(
        string $e2eid,
        string $id,
        float|int|string $valor,
        NaturezaDevolucao|string $natureza = NaturezaDevolucao::Original,
        ?string $descricao = null,
    ): Devolucao {
        $this->validarE2eid($e2eid);
        $this->validarIdDevolucao($id);

        $natureza = $natureza instanceof NaturezaDevolucao ? $natureza : NaturezaDevolucao::tryFrom(strtoupper(trim($natureza)))
            ?? throw new InvalidArgumentException("Natureza de devolução inválida: \"{$natureza}\".");

        $config = $this->config();
        $corpo = array_filter([
            'valor' => FormataPix::valor($valor),
            'natureza' => $natureza->value,
            'descricao' => FormataPix::texto($descricao, 140),
        ], fn($v) => $v !== null);

        return Devolucao::fromArray($this->json($this->enviar($config, 'put', "/pix/{$e2eid}/devolucao/{$id}", 'solicitar a devolução', json: $corpo)));
    }

    public function consultarDevolucao(string $e2eid, string $id): Devolucao
    {
        $this->validarE2eid($e2eid);
        $this->validarIdDevolucao($id);
        $config = $this->config();

        return Devolucao::fromArray($this->json($this->enviar($config, 'get', "/pix/{$e2eid}/devolucao/{$id}", 'consultar a devolução')));
    }

    // ---------------------------------------------------------------------
    // Webhook da chave Pix
    // ---------------------------------------------------------------------

    /**
     * Configura a URL que o Sicredi chama a cada Pix recebido na chave (PUT /webhook/{chave}).
     * Pelo padrão do Banco Central, a chamada chega em {webhookUrl}/pix.
     */
    public function configurarWebhook(string $url, ?string $chave = null): void
    {
        if (! str_starts_with(strtolower(trim($url)), 'https://')) {
            throw new InvalidArgumentException('A URL do webhook Pix deve ser HTTPS.');
        }

        $config = $this->config();
        $this->enviar($config, 'put', '/webhook/' . rawurlencode($chave ?? $config->chavePixObrigatoria()), 'configurar o webhook', json: [
            'webhookUrl' => trim($url),
        ]);
    }

    public function consultarWebhook(?string $chave = null): WebhookPix
    {
        $config = $this->config();

        return WebhookPix::fromArray($this->json($this->enviar($config, 'get', '/webhook/' . rawurlencode($chave ?? $config->chavePixObrigatoria()), 'consultar o webhook')));
    }

    public function removerWebhook(?string $chave = null): void
    {
        $config = $this->config();
        $this->enviar($config, 'delete', '/webhook/' . rawurlencode($chave ?? $config->chavePixObrigatoria()), 'remover o webhook');
    }

    // ---------------------------------------------------------------------
    // HTTP
    // ---------------------------------------------------------------------

    /**
     * @param array<string, scalar|null> $filtros
     * @return Generator<int, array<string, mixed>>
     */
    private function paginar(
        string $caminho,
        string $operacao,
        string $chaveItens,
        DateTimeInterface|string $inicio,
        DateTimeInterface|string $fim,
        array $filtros,
    ): Generator {
        $config = $this->config();
        $itensPorPagina = isset($filtros['itensPorPagina']) ? (string) $filtros['itensPorPagina'] : null;
        unset($filtros['itensPorPagina'], $filtros['paginaAtual']);

        $query = ['inicio' => FormataPix::dataHoraUtc($inicio), 'fim' => FormataPix::dataHoraUtc($fim)];
        foreach ($filtros as $nome => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }
            $query[$nome] = match (true) {
                is_bool($valor) => $valor ? 'true' : 'false',
                in_array($nome, ['cpf', 'cnpj'], true) => (string) preg_replace('/\D/', '', (string) $valor),
                default => (string) $valor,
            };
        }

        for ($pagina = 0; $pagina < self::MAX_PAGINAS; $pagina++) {
            $query['paginacao.paginaAtual'] = (string) $pagina;
            if ($itensPorPagina !== null) {
                $query['paginacao.itensPorPagina'] = $itensPorPagina;
            }

            $dados = $this->json($this->enviar($config, 'get', $caminho, $operacao, query: $query));

            foreach ((array) ($dados[$chaveItens] ?? []) as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            $paginacao = is_array($dados['parametros']['paginacao'] ?? null) ? $dados['parametros']['paginacao'] : [];
            $paginas = (int) ($paginacao['quantidadeDePaginas'] ?? 1);
            $atual = (int) ($paginacao['paginaAtual'] ?? $pagina);

            if ($atual + 1 >= $paginas) {
                return;
            }
            $pagina = $atual;
        }
    }

    /**
     * @param array<string, string> $query
     * @param array<mixed>|null $json
     */
    private function enviar(
        PixConfig $config,
        string $metodo,
        string $caminho,
        string $operacao,
        array $query = [],
        ?array $json = null,
    ): Response {
        $tokens = $this->tokens($config);
        $url = $config->baseUrl . $caminho;

        for ($tentativa = 1; ; $tentativa++) {
            $request = $this->http
                ->withOptions($config->opcoesHttp())
                ->timeout($config->timeout)
                ->connectTimeout($config->connectTimeout)
                ->withToken($tokens->token())
                ->accept('application/json');

            if ($query !== []) {
                $request = $request->withQueryParameters($query);
            }

            try {
                /** @var Response $response */
                $response = $json !== null
                    ? $request->asJson()->send(strtoupper($metodo), $url, ['json' => $json])
                    : $request->send(strtoupper($metodo), $url);
            } catch (ConnectionException|TransferException $e) {
                throw new PixException("Sicredi Pix: falha de conexão ao {$operacao}: " . $e->getMessage(), 0, null, $e);
            }

            // Token revogado antes do prazo: descarta e tenta uma vez com um novo.
            if ($response->status() === 401 && $tentativa === 1) {
                $tokens->esquecer();
                continue;
            }

            if (! $response->successful()) {
                throw PixException::daResposta($response, $operacao);
            }

            return $response;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $dados = $response->json();

        if (! is_array($dados)) {
            throw new PixException('Sicredi Pix: resposta inesperada (não é JSON).', $response->status(), mb_substr($response->body(), 0, 500));
        }

        return $dados;
    }

    private function validarTxid(string $txid): void
    {
        if (! self::txidValido($txid)) {
            throw new InvalidArgumentException("txid inválido: \"{$txid}\" (esperado [a-zA-Z0-9]{26,35}).");
        }
    }

    private function validarE2eid(string $e2eid): void
    {
        if (preg_match('/^[a-zA-Z0-9]{32}$/', $e2eid) !== 1) {
            throw new InvalidArgumentException("endToEndId inválido: \"{$e2eid}\" (esperado 32 caracteres alfanuméricos).");
        }
    }

    private function validarIdDevolucao(string $id): void
    {
        if (preg_match('/^[a-zA-Z0-9]{1,35}$/', $id) !== 1) {
            throw new InvalidArgumentException("id de devolução inválido: \"{$id}\" (esperado [a-zA-Z0-9]{1,35}).");
        }
    }
}
