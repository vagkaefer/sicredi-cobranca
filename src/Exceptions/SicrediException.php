<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Erro devolvido pela API do Sicredi (ou falha de comunicação, com status 0).
 */
class SicrediException extends RuntimeException
{
    /**
     * @param array<mixed>|string|null $corpo
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array|string|null $corpo = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function daResposta(Response $response, string $operacao): self
    {
        $status = $response->status();
        $corpo = $response->json() ?? $response->body();
        $detalhe = self::extrairMensagem($corpo) ?? "HTTP {$status}";
        $mensagem = "Sicredi: falha ao {$operacao} (HTTP {$status}): {$detalhe}";

        return match (true) {
            $status === 401, $status === 403 => new AutenticacaoException($mensagem, $status, $corpo),
            $status === 404 => new NaoEncontradoException($mensagem, $status, $corpo),
            $status === 400, $status === 422 => new ValidacaoException($mensagem, $status, $corpo),
            $status === 429 => new LimiteRequisicoesException($mensagem, $status, $corpo),
            default => new self($mensagem, $status, $corpo),
        };
    }

    /**
     * Junta as mensagens em qualquer um dos formatos de erro vistos na API.
     *
     * @param array<mixed>|string|null $corpo
     */
    public static function extrairMensagem(array|string|null $corpo): ?string
    {
        if (is_string($corpo)) {
            $texto = trim(strip_tags($corpo));

            return $texto === '' ? null : mb_substr($texto, 0, 500);
        }

        if (! is_array($corpo)) {
            return null;
        }

        $partes = [];

        foreach (['message', 'mensagem', 'error_description', 'detail', 'error'] as $chave) {
            if (isset($corpo[$chave]) && is_string($corpo[$chave]) && $corpo[$chave] !== '') {
                $partes[] = $corpo[$chave];
                break;
            }
        }

        foreach (['erros', 'errors', 'details', 'detalhes'] as $chave) {
            if (! isset($corpo[$chave]) || ! is_array($corpo[$chave])) {
                continue;
            }
            foreach ($corpo[$chave] as $campo => $erro) {
                if (is_array($erro)) {
                    $texto = $erro['mensagem'] ?? $erro['message'] ?? $erro['descricao'] ?? json_encode($erro, JSON_UNESCAPED_UNICODE);
                    $nome = $erro['campo'] ?? $erro['field'] ?? (is_string($campo) ? $campo : null);
                    $partes[] = $nome ? "{$nome}: {$texto}" : (string) $texto;
                } elseif (is_string($erro)) {
                    $partes[] = is_string($campo) ? "{$campo}: {$erro}" : $erro;
                }
            }
        }

        return $partes === [] ? null : implode(' | ', array_unique($partes));
    }
}
