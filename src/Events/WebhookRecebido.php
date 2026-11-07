<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Events;

/**
 * Evento de webhook recebido do Sicredi (liquidação, estorno...).
 *
 * O Sicredi não assina as chamadas: antes de dar baixa financeira, reconsulte o boleto
 * com SicrediCobranca::consultarBoleto($evento->nossoNumero).
 */
class WebhookRecebido
{
    /**
     * @param array<mixed> $payload Corpo recebido, sem tratamento.
     */
    public function __construct(
        public readonly array $payload,
        public readonly ?string $nossoNumero,
        public readonly ?string $evento,
        public readonly ?string $idTituloEmpresa,
    ) {}
}
