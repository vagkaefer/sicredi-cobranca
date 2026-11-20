<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * API Pix do Sicredi (experimental).
 *
 * @method static void resolverConfigUsing(\Closure $resolver)
 * @method static \VagKaefer\SicrediCobranca\Pix\SicrediPix usando(\VagKaefer\SicrediCobranca\Pix\PixConfig $config)
 * @method static \VagKaefer\SicrediCobranca\Pix\PixConfig config()
 * @method static \VagKaefer\SicrediCobranca\Pix\PixTokenManager tokens(?\VagKaefer\SicrediCobranca\Pix\PixConfig $config = null)
 * @method static array<string, mixed> opcoesHttp()
 * @method static string gerarTxid(int $tamanho = 32)
 * @method static bool txidValido(string $txid)
 * @method static \VagKaefer\SicrediCobranca\Pix\Data\Cobranca criarCobranca(\VagKaefer\SicrediCobranca\Pix\Data\CobRequest $cobranca, ?string $txid = null)
 * @method static \VagKaefer\SicrediCobranca\Pix\Data\Cobranca consultarCobranca(string $txid, ?int $revisao = null)
 * @method static \VagKaefer\SicrediCobranca\Pix\Data\Cobranca revisarCobranca(string $txid, array<string, mixed> $dados)
 * @method static \VagKaefer\SicrediCobranca\Pix\Data\Cobranca cancelarCobranca(string $txid)
 * @method static \Generator<int, \VagKaefer\SicrediCobranca\Pix\Data\Cobranca> listarCobrancas(\DateTimeInterface|string $inicio, \DateTimeInterface|string $fim, array<string, scalar|null> $filtros = [])
 * @method static \Generator<int, \VagKaefer\SicrediCobranca\Pix\Data\PixRecebido> pixRecebidos(\DateTimeInterface|string $inicio, \DateTimeInterface|string $fim, array<string, scalar|null> $filtros = [])
 * @method static \VagKaefer\SicrediCobranca\Pix\Data\PixRecebido consultarPix(string $e2eid)
 * @method static \VagKaefer\SicrediCobranca\Pix\Data\Devolucao devolver(string $e2eid, string $id, float|int|string $valor, \VagKaefer\SicrediCobranca\Pix\Enums\NaturezaDevolucao|string $natureza = \VagKaefer\SicrediCobranca\Pix\Enums\NaturezaDevolucao::Original, ?string $descricao = null)
 * @method static \VagKaefer\SicrediCobranca\Pix\Data\Devolucao consultarDevolucao(string $e2eid, string $id)
 * @method static void configurarWebhook(string $url, ?string $chave = null)
 * @method static \VagKaefer\SicrediCobranca\Pix\Data\WebhookPix consultarWebhook(?string $chave = null)
 * @method static void removerWebhook(?string $chave = null)
 *
 * @see \VagKaefer\SicrediCobranca\Pix\SicrediPix
 */
class SicrediPix extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \VagKaefer\SicrediCobranca\Pix\SicrediPix::class;
    }
}
