<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Events;

use VagKaefer\SicrediCobranca\Pix\Data\PixRecebido as Pix;

/**
 * Pix recebido pelo webhook da API Pix (um evento por item do lote).
 *
 * Antes de dar baixa financeira, reconsulte a cobrança com SicrediPix::consultarCobranca($evento->pix->txid)
 * (ou o Pix com consultarPix($evento->pix->endToEndId)): a origem da chamada só é garantida pelo mTLS,
 * que fica a cargo do servidor web.
 *
 * @experimental Ainda não validado com chamadas reais do Sicredi; pode mudar numa versão menor.
 */
class PixRecebido
{
    /**
     * @param array<mixed> $payload Item recebido, sem tratamento.
     */
    public function __construct(
        public readonly Pix $pix,
        public readonly array $payload,
    ) {}
}
