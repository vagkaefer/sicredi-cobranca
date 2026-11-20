<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Exceptions;

use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Erro da API Pix do Sicredi (ou falha de comunicação, com status 0).
 *
 * A API responde no formato RFC 7807 (application/problem+json). O correlationId vai na mensagem
 * porque é o que o suporte do Sicredi pede para investigar uma chamada.
 */
class PixException extends SicrediException
{
    /**
     * @param array<mixed>|string|null $corpo
     * @param list<array{razao: string, propriedade: ?string}> $violacoes
     */
    public function __construct(
        string $message,
        int $status = 0,
        array|string|null $corpo = null,
        ?Throwable $previous = null,
        public readonly ?string $correlationId = null,
        public readonly array $violacoes = [],
        public readonly ?string $tipo = null,
        public readonly ?string $titulo = null,
        public readonly ?string $detalhe = null,
    ) {
        parent::__construct($message, $status, $corpo, $previous);
    }

    public static function daResposta(Response $response, string $operacao): self
    {
        $status = $response->status();
        $corpo = $response->json() ?? $response->body();
        $problema = is_array($corpo) ? $corpo : [];

        $titulo = self::campo($problema, 'title');
        $detalhe = self::campo($problema, 'detail');
        $tipo = self::campo($problema, 'type');
        $correlationId = self::campo($problema, 'correlationId');
        if ($correlationId === null) {
            $header = $response->header('x-correlation-id') ?: $response->header('correlationId');
            $correlationId = $header !== '' ? $header : null;
        }
        $violacoes = self::violacoes($problema['violacoes'] ?? null);

        $partes = array_values(array_filter([$titulo, $detalhe]));
        foreach ($violacoes as $v) {
            $partes[] = $v['propriedade'] !== null ? "{$v['propriedade']}: {$v['razao']}" : $v['razao'];
        }
        if ($partes === []) {
            $partes[] = SicrediException::extrairMensagem($corpo) ?? "HTTP {$status}";
        }

        $mensagem = "Sicredi Pix: falha ao {$operacao} (HTTP {$status}): " . implode(' | ', array_unique($partes));
        if ($status === 403) {
            $mensagem .= ' (confira os escopos do token e o certificado mTLS)';
        }
        if ($correlationId !== null) {
            $mensagem .= " [correlationId: {$correlationId}]";
        }

        $classe = match (true) {
            $status === 401, $status === 403 => PixAutenticacaoException::class,
            $status === 404 => PixNaoEncontradoException::class,
            $status === 400, $status === 422 => PixValidacaoException::class,
            $status === 429 => PixLimiteRequisicoesException::class,
            default => self::class,
        };

        return new $classe($mensagem, $status, $corpo, null, $correlationId, $violacoes, $tipo, $titulo, $detalhe);
    }

    /**
     * @param array<mixed> $dados
     */
    private static function campo(array $dados, string $chave): ?string
    {
        $valor = $dados[$chave] ?? null;

        return is_scalar($valor) && trim((string) $valor) !== '' ? trim((string) $valor) : null;
    }

    /**
     * @return list<array{razao: string, propriedade: ?string}>
     */
    private static function violacoes(mixed $dados): array
    {
        if (! is_array($dados)) {
            return [];
        }

        $lista = [];
        foreach ($dados as $v) {
            if (! is_array($v)) {
                continue;
            }
            $razao = self::campo($v, 'razao');
            if ($razao !== null) {
                $lista[] = ['razao' => $razao, 'propriedade' => self::campo($v, 'propriedade')];
            }
        }

        return $lista;
    }
}
