<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca;

use Closure;
use DateTimeInterface;
use Generator;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use LogicException;
use VagKaefer\SicrediCobranca\Auth\TokenManager;
use VagKaefer\SicrediCobranca\Data\Boleto;
use VagKaefer\SicrediCobranca\Data\BoletoCriado;
use VagKaefer\SicrediCobranca\Data\BoletoRequest;
use VagKaefer\SicrediCobranca\Data\ComandoResponse;
use VagKaefer\SicrediCobranca\Data\Formata;
use VagKaefer\SicrediCobranca\Data\Liquidacao;
use VagKaefer\SicrediCobranca\Enums\Ambiente;
use VagKaefer\SicrediCobranca\Exceptions\SicrediException;

/**
 * Cliente da API de Cobrança do Sicredi.
 */
class SicrediCobranca
{
    /** Proteção contra laço infinito na paginação. */
    private const MAX_PAGINAS = 1000;

    /** @var Closure(): Config */
    private Closure $resolver;

    /**
     * @param Config|Closure(): Config $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheFactory $cache,
        Config|Closure $config,
    ) {
        $this->resolver = $config instanceof Config ? fn() => $config : $config;
    }

    /**
     * Define de onde vem a configuração (ex.: banco de dados). Chamado a cada operação.
     *
     * @param Closure(): (Config|array<string, mixed>) $resolver
     */
    public function resolverConfigUsing(Closure $resolver): void
    {
        $this->resolver = function () use ($resolver): Config {
            $config = $resolver();

            return $config instanceof Config ? $config : Config::fromArray($config);
        };
    }

    /**
     * Novo cliente com uma configuração fixa (ex.: outra conta ou outro ambiente).
     */
    public function usando(Config $config): self
    {
        return new self($this->http, $this->cache, $config);
    }

    public function config(): Config
    {
        return ($this->resolver)();
    }

    public function tokens(?Config $config = null): TokenManager
    {
        return new TokenManager($config ?? $this->config(), $this->http, $this->cache);
    }

    // ---------------------------------------------------------------------
    // Boletos
    // ---------------------------------------------------------------------

    public function criarBoleto(BoletoRequest $boleto): BoletoCriado
    {
        $config = $this->config();
        $corpo = $boleto->toArray($config->codigoBeneficiario);

        $response = $this->enviar($config, 'post', $config->ambiente->urlCobranca() . '/boletos', 'registrar o boleto', json: $corpo);

        return BoletoCriado::fromArray($this->json($response));
    }

    public function consultarBoleto(string $nossoNumero): Boleto
    {
        $config = $this->config();

        $response = $this->enviar($config, 'get', $config->ambiente->urlCobranca() . '/boletos', 'consultar o boleto', query: [
            'codigoBeneficiario' => $config->codigoBeneficiario,
            'nossoNumero' => Formata::digitos($nossoNumero),
        ]);

        return Boleto::fromArray($this->json($response));
    }

    /**
     * PDF do boleto (bytes). Não recalcula encargos alterados depois da emissão.
     */
    public function pdf(string $linhaDigitavel): string
    {
        $config = $this->config();

        $response = $this->enviar($config, 'get', $config->ambiente->urlCobranca() . '/boletos/pdf', 'obter o PDF do boleto', query: [
            'linhaDigitavel' => Formata::digitos($linhaDigitavel),
        ], accept: 'application/pdf, application/octet-stream');

        $pdf = $response->body();
        if (! str_starts_with($pdf, '%PDF')) {
            throw new SicrediException('Sicredi: a resposta do PDF não é um arquivo PDF.', $response->status(), mb_substr($pdf, 0, 500));
        }

        return $pdf;
    }

    /**
     * Títulos cadastrados nas últimas 48h, por idTituloEmpresa ou seuNumero (informe só um).
     * Não disponível no sandbox.
     *
     * @return list<array<string, mixed>>
     */
    public function cadastrados(?string $idTituloEmpresa = null, ?string $seuNumero = null): array
    {
        if (($idTituloEmpresa === null) === ($seuNumero === null)) {
            throw new LogicException('Informe exatamente um entre idTituloEmpresa e seuNumero.');
        }

        $config = $this->config();
        $query = $idTituloEmpresa !== null ? ['idTituloEmpresa' => $idTituloEmpresa] : ['seuNumero' => $seuNumero];

        $dados = $this->json($this->enviar($config, 'get', $config->ambiente->urlCobranca() . '/boletos/cadastrados', 'consultar títulos cadastrados', query: $query));

        // A API pode devolver uma lista, um objeto único ou {items: [...]}.
        if (isset($dados['items']) && is_array($dados['items'])) {
            $dados = $dados['items'];
        }

        return array_is_list($dados) ? $dados : [$dados];
    }

    /**
     * Percorre todas as páginas de boletos liquidados no dia.
     *
     * @return Generator<int, Liquidacao>
     */
    public function liquidadosPorDia(DateTimeInterface|string $dia, ?string $cpfCnpjBeneficiarioFinal = null): Generator
    {
        $config = $this->config();
        $data = (new \DateTimeImmutable(Formata::data($dia)))->format('d/m/Y');

        for ($pagina = 0; $pagina < self::MAX_PAGINAS; $pagina++) {
            $query = array_filter([
                'codigoBeneficiario' => $config->codigoBeneficiario,
                'dia' => $data,
                'pagina' => (string) $pagina,
                'cpfCnpjBeneficiarioFinal' => $cpfCnpjBeneficiarioFinal ? Formata::digitos($cpfCnpjBeneficiarioFinal) : null,
            ]);

            $dados = $this->json($this->enviar($config, 'get', $config->ambiente->urlCobranca() . '/boletos/liquidados/dia', 'consultar liquidados do dia', query: $query));

            foreach ((array) ($dados['items'] ?? []) as $item) {
                if (is_array($item)) {
                    yield Liquidacao::fromArray($item);
                }
            }

            if (empty($dados['hasNext'])) {
                return;
            }
        }
    }

    // ---------------------------------------------------------------------
    // Comandos de instrução (PATCH /boletos/{nossoNumero}/...). Respondem 202 e são assíncronos.
    // ---------------------------------------------------------------------

    public function baixar(string $nossoNumero): ComandoResponse
    {
        return $this->comando($nossoNumero, 'baixa');
    }

    public function alterarVencimento(string $nossoNumero, DateTimeInterface|string $dataVencimento): ComandoResponse
    {
        return $this->comando($nossoNumero, 'data-vencimento', ['dataVencimento' => Formata::data($dataVencimento)]);
    }

    /**
     * Altera só o valor/percentual; o tipo de juros continua o mesmo.
     */
    public function alterarJuros(string $nossoNumero, float $valorOuPercentual): ComandoResponse
    {
        return $this->comando($nossoNumero, 'juros', ['valorOuPercentual' => Formata::valor($valorOuPercentual)]);
    }

    public function alterarDesconto(string $nossoNumero, float $valor1, ?float $valor2 = null, ?float $valor3 = null): ComandoResponse
    {
        return $this->comando($nossoNumero, 'desconto', array_filter([
            'valorDesconto1' => Formata::valor($valor1),
            'valorDesconto2' => $valor2 !== null ? Formata::valor($valor2) : null,
            'valorDesconto3' => $valor3 !== null ? Formata::valor($valor3) : null,
        ], fn($v) => $v !== null));
    }

    public function alterarSeuNumero(string $nossoNumero, string $seuNumero): ComandoResponse
    {
        return $this->comando($nossoNumero, 'seu-numero', ['seuNumero' => Formata::texto($seuNumero, 10)]);
    }

    public function concederAbatimento(string $nossoNumero, float $valor): ComandoResponse
    {
        return $this->comando($nossoNumero, 'conceder-abatimento', ['valorAbatimento' => Formata::valor($valor)]);
    }

    public function cancelarAbatimento(string $nossoNumero): ComandoResponse
    {
        return $this->comando($nossoNumero, 'cancelar-abatimento');
    }

    /**
     * Qualquer comando de instrução (ex.: "protesto", "sustar-protesto-baixar-titulo", "negativacao").
     *
     * @param array<string, mixed> $corpo
     */
    public function comando(string $nossoNumero, string $acao, array $corpo = []): ComandoResponse
    {
        $config = $this->config();
        $url = $config->ambiente->urlCobranca() . '/boletos/' . Formata::digitos($nossoNumero) . '/' . trim($acao, '/');

        $response = $this->enviar($config, 'patch', $url, "executar o comando \"{$acao}\"", json: (object) $corpo, headers: [
            'codigoBeneficiario' => $config->codigoBeneficiario,
        ]);

        return ComandoResponse::fromArray($this->json($response));
    }

    // ---------------------------------------------------------------------
    // Webhook (somente produção, experimental: o formato dos eventos não é documentado)
    // ---------------------------------------------------------------------

    /**
     * Contrata o webhook de liquidação. Cada beneficiário tem um contrato por cooperativa.
     *
     * @param list<string> $eventos
     * @param array<string, mixed> $extras nomeResponsavel, email, telefone, enviarIdTituloEmpresa...
     * @return array<string, mixed>
     */
    public function registrarWebhook(string $url, array $eventos = ['LIQUIDACAO'], array $extras = []): array
    {
        $config = $this->configProducao();

        return $this->json($this->enviar($config, 'post', $config->ambiente->urlCobranca() . '/webhook/contrato/', 'contratar o webhook', json: array_merge([
            'cooperativa' => $config->cooperativa,
            'posto' => $config->posto,
            // Neste endpoint o campo se chama codBeneficiario.
            'codBeneficiario' => $config->codigoBeneficiario,
            'eventos' => $eventos,
            'url' => $url,
            'urlStatus' => 'ATIVO',
            'contratoStatus' => 'ATIVO',
        ], $extras)));
    }

    /**
     * @return array<mixed>
     */
    public function listarWebhooks(): array
    {
        $config = $this->configProducao();

        return $this->json($this->enviar($config, 'get', $config->ambiente->urlCobranca() . '/webhook/contratos/', 'consultar contratos de webhook', query: [
            'cooperativa' => $config->cooperativa,
            'posto' => $config->posto,
            'beneficiario' => $config->codigoBeneficiario,
        ]));
    }

    /**
     * @param array<string, mixed> $dados Campos do contrato (url, eventos, urlStatus, contratoStatus...).
     * @return array<mixed>
     */
    public function atualizarWebhook(string $idContrato, array $dados): array
    {
        $config = $this->configProducao();

        return $this->json($this->enviar($config, 'put', $config->ambiente->urlCobranca() . '/webhook/contrato/' . rawurlencode($idContrato), 'atualizar o webhook', json: array_merge([
            'cooperativa' => $config->cooperativa,
            'posto' => $config->posto,
            'codBeneficiario' => $config->codigoBeneficiario,
        ], $dados)));
    }

    // ---------------------------------------------------------------------
    // HTTP
    // ---------------------------------------------------------------------

    /**
     * @param array<string, string> $query
     * @param array<mixed>|object|null $json
     * @param array<string, string> $headers
     */
    private function enviar(
        Config $config,
        string $metodo,
        string $url,
        string $operacao,
        array $query = [],
        array|object|null $json = null,
        array $headers = [],
        string $accept = 'application/json',
    ): Response {
        $tokens = $this->tokens($config);

        for ($tentativa = 1; ; $tentativa++) {
            $request = $this->http
                ->timeout($config->timeout)
                ->connectTimeout($config->connectTimeout)
                ->withToken($tokens->token())
                ->withHeaders(array_merge([
                    'x-api-key' => $config->apiKey,
                    'cooperativa' => $config->cooperativa,
                    'posto' => $config->posto,
                    'Accept' => $accept,
                ], $headers));

            if ($query !== []) {
                $request = $request->withQueryParameters($query);
            }

            try {
                /** @var Response $response */
                $response = $json !== null
                    ? $request->asJson()->send(strtoupper($metodo), $url, ['json' => $json])
                    : $request->send(strtoupper($metodo), $url);
            } catch (ConnectionException $e) {
                throw new SicrediException("Sicredi: falha de conexão ao {$operacao}: " . $e->getMessage(), 0, null, $e);
            }

            // Token revogado antes do prazo: descarta e tenta uma vez com um novo.
            if ($response->status() === 401 && $tentativa === 1) {
                $tokens->esquecer();
                continue;
            }

            if (! $response->successful()) {
                throw SicrediException::daResposta($response, $operacao);
            }

            return $response;
        }
    }

    /**
     * @return array<mixed>
     */
    private function json(Response $response): array
    {
        $dados = $response->json();

        if (! is_array($dados)) {
            throw new SicrediException('Sicredi: resposta inesperada (não é JSON).', $response->status(), mb_substr($response->body(), 0, 500));
        }

        return $dados;
    }

    private function configProducao(): Config
    {
        $config = $this->config();

        if ($config->ambiente !== Ambiente::Producao) {
            throw new LogicException('O webhook de cobrança do Sicredi só existe em produção.');
        }

        return $config;
    }
}
