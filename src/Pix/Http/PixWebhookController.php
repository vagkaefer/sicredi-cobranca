<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Pix\Http;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use VagKaefer\SicrediCobranca\Events\PixRecebido;
use VagKaefer\SicrediCobranca\Pix\Data\PixRecebido as Pix;
use VagKaefer\SicrediCobranca\Pix\SicrediPix;

/**
 * Recebe a callback do webhook Pix ({"pix": [...]}) e dispara PixRecebido por item.
 *
 * Pelo padrão do Banco Central o PSP chama {webhookUrl}/pix; por isso a rota existe com e sem o
 * sufixo /pix. Responde 200 rápido: processe o evento numa fila.
 *
 * @experimental Ainda não validado com chamadas reais do Sicredi; pode mudar numa versão menor.
 */
class PixWebhookController
{
    private const PREFIXO = 'sicredi-cobranca.pix.webhook.';

    public function __invoke(Request $request, SicrediPix $pix, Dispatcher $eventos, ConfigRepository $config): JsonResponse
    {
        // Verificação de mTLS feita pelo proxy/servidor web (ex.: nginx com ssl_verify_client).
        $headerMtls = (string) $config->get(self::PREFIXO . 'mtls_header', '');
        if ($headerMtls !== '') {
            $esperadoMtls = (string) $config->get(self::PREFIXO . 'mtls_valor', 'SUCCESS');
            if (! hash_equals($esperadoMtls, (string) $request->header($headerMtls, ''))) {
                return new JsonResponse(['erro' => 'certificado do cliente não verificado'], 403);
            }
        }

        [$esperado, $configFalhou] = $this->tokenEsperado($pix, $config);

        if ($esperado === null) {
            // Sem token conhecido, só aceita se o app desligou a exigência de propósito. Configuração
            // com erro nunca abre o webhook: recusa e o PSP reenvia depois.
            if ($configFalhou || (bool) $config->get(self::PREFIXO . 'exigir_token', true)) {
                return new JsonResponse(['erro' => 'webhook Pix sem token configurado'], 503);
            }
        } elseif (! $this->tokenConfere($request, $config, $esperado)) {
            return new JsonResponse(['erro' => 'token inválido'], 401);
        }

        $itens = $request->json('pix');
        $itens = is_array($itens) ? $itens : [];

        $recebidos = 0;
        foreach ($itens as $item) {
            if (! is_array($item)) {
                continue;
            }

            $eventos->dispatch(new PixRecebido(Pix::fromArray($item), $item));
            $recebidos++;
        }

        // Corpo sem "pix" (ex.: teste de alcance ao configurar o webhook) também recebe 200.
        return new JsonResponse(['ok' => true, 'recebidos' => $recebidos]);
    }

    private function tokenConfere(Request $request, ConfigRepository $config, string $esperado): bool
    {
        $header = (string) $config->get(self::PREFIXO . 'header', 'X-Sicredi-Token');
        $recebido = (string) ($request->header($header) ?? $request->query('token', ''));

        // Se a URL registrada tiver ?token=..., o PSP que só concatena "/pix" no fim entrega "token=SEGREDO/pix".
        if (str_ends_with($recebido, '/pix') && ! hash_equals($esperado, $recebido)) {
            $recebido = substr($recebido, 0, -4);
        }

        return $recebido !== '' && hash_equals($esperado, $recebido);
    }

    /**
     * Ordem: resolver explícito (resolverWebhookTokenUsing), configuração do cliente Pix e arquivo
     * de config. Devolve também se a leitura da configuração falhou.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function tokenEsperado(SicrediPix $pix, ConfigRepository $config): array
    {
        $token = $pix->webhookTokenExplicito();
        $falhou = false;

        if ($token === null) {
            try {
                $token = $pix->config()->webhookToken;
            } catch (Throwable $e) {
                Log::warning('Sicredi Pix webhook: não foi possível ler a configuração: ' . $e->getMessage());
                $falhou = true;
            }
        }

        $token ??= $config->get(self::PREFIXO . 'token');
        $token = is_string($token) && trim($token) !== '' ? trim($token) : null;

        // Com o token do arquivo de config disponível, a falha na configuração não impede a validação.
        return [$token, $falhou && $token === null];
    }
}
