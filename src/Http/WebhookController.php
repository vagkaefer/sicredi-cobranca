<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Http;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use VagKaefer\SicrediCobranca\Events\WebhookRecebido;
use VagKaefer\SicrediCobranca\SicrediCobranca;

/**
 * Recebe os eventos do webhook e só dispara WebhookRecebido.
 * O Sicredi exige resposta 200 em até 10s: processe o evento numa fila.
 */
class WebhookController
{
    public function __invoke(Request $request, SicrediCobranca $sicredi, Dispatcher $eventos, ConfigRepository $config): JsonResponse
    {
        $esperado = $this->tokenEsperado($sicredi, $config);

        if ($esperado !== null) {
            $header = (string) $config->get('sicredi-cobranca.webhook.header', 'X-Sicredi-Token');
            $recebido = (string) ($request->header($header) ?? $request->query('token', ''));

            if ($recebido === '' || ! hash_equals($esperado, $recebido)) {
                return new JsonResponse(['erro' => 'token inválido'], 401);
            }
        }

        $payload = $request->json()->all();
        if ($payload === []) {
            $payload = $request->all();
        }

        // Alguns integradores mandam um lote; trata cada item como um evento.
        $itens = array_is_list($payload) && $payload !== [] ? $payload : [$payload];

        foreach ($itens as $item) {
            $item = is_array($item) ? $item : ['valor' => $item];

            $eventos->dispatch(new WebhookRecebido(
                payload: $item,
                nossoNumero: self::buscar($item, ['nossoNumero', 'nosso_numero', 'nossonumero']),
                evento: self::buscar($item, ['movimento', 'evento', 'tipoEvento', 'tipoMovimento', 'event']),
                idTituloEmpresa: self::buscar($item, ['idTituloEmpresa']),
            ));
        }

        return new JsonResponse(['ok' => true]);
    }

    private function tokenEsperado(SicrediCobranca $sicredi, ConfigRepository $config): ?string
    {
        try {
            $token = $sicredi->config()->webhookToken;
        } catch (Throwable $e) {
            // Configuração incompleta não pode derrubar o recebimento; cai no token do arquivo de config.
            Log::warning('Sicredi webhook: não foi possível ler a configuração: ' . $e->getMessage());
            $token = null;
        }

        $token ??= $config->get('sicredi-cobranca.webhook.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Procura a primeira das chaves em qualquer nível do payload (o formato não é documentado).
     *
     * @param array<mixed> $dados
     * @param list<string> $chaves
     */
    private static function buscar(array $dados, array $chaves): ?string
    {
        foreach ($chaves as $chave) {
            if (isset($dados[$chave]) && is_scalar($dados[$chave]) && (string) $dados[$chave] !== '') {
                return (string) $dados[$chave];
            }
        }

        foreach ($dados as $valor) {
            if (is_array($valor) && ($achado = self::buscar($valor, $chaves)) !== null) {
                return $achado;
            }
        }

        return null;
    }
}
