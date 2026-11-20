<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests\Feature\Pix;

use Illuminate\Support\Facades\Event;
use VagKaefer\SicrediCobranca\Events\PixRecebido;
use VagKaefer\SicrediCobranca\Events\WebhookRecebido;
use VagKaefer\SicrediCobranca\Tests\TestCase;

class PixWebhookTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function lote(int $quantidade): array
    {
        $pix = [];
        for ($i = 1; $i <= $quantidade; $i++) {
            $pix[] = [
                'endToEndId' => sprintf('E1234567820261120130%012d', $i),
                'txid' => str_repeat('T', 31) . $i,
                'valor' => '10.00',
                'horario' => '2026-11-20T13:05:00.000Z',
                'chave' => self::PIX_CHAVE,
                'infoPagador' => 'pagamento',
                'devolucoes' => [],
            ];
        }

        return ['pix' => $pix];
    }

    public function test_registra_as_duas_rotas(): void
    {
        $this->assertTrue(app('router')->has('sicredi-cobranca.pix.webhook'));
        $this->assertTrue(app('router')->has('sicredi-cobranca.pix.webhook.pix'));
        // O webhook da Cobrança continua lá.
        $this->assertTrue(app('router')->has('sicredi-cobranca.webhook'));
    }

    public function test_recebe_lote_nas_duas_rotas(): void
    {
        Event::fake();

        $this->postJson('/sicredi/pix/webhook/pix?token=segredo-pix', self::lote(3))
            ->assertOk()->assertJson(['ok' => true, 'recebidos' => 3]);
        $this->postJson('/sicredi/pix/webhook?token=segredo-pix', self::lote(1))
            ->assertOk()->assertJson(['recebidos' => 1]);

        Event::assertDispatchedTimes(PixRecebido::class, 4);
        Event::assertDispatched(PixRecebido::class, fn(PixRecebido $e) => $e->pix->txid === str_repeat('T', 31) . '3'
            && $e->pix->valor === 10.0
            && $e->pix->endToEndId === 'E1234567820261120130000000000003'
            && $e->payload['infoPagador'] === 'pagamento');
        Event::assertNotDispatched(WebhookRecebido::class);
    }

    public function test_token_no_header_e_com_sufixo_pix_concatenado(): void
    {
        Event::fake();

        $this->postJson('/sicredi/pix/webhook/pix', self::lote(1), ['X-Sicredi-Token' => 'segredo-pix'])->assertOk();
        // URL registrada com ?token=...: o PSP que só concatena "/pix" entrega "token=segredo-pix/pix".
        $this->postJson('/sicredi/pix/webhook?token=segredo-pix/pix', self::lote(1))->assertOk();

        Event::assertDispatchedTimes(PixRecebido::class, 2);
    }

    public function test_recusa_sem_token_ou_token_errado(): void
    {
        Event::fake();

        $this->postJson('/sicredi/pix/webhook/pix', self::lote(1))->assertStatus(401);
        $this->postJson('/sicredi/pix/webhook/pix?token=errado', self::lote(1))->assertStatus(401);
        $this->postJson('/sicredi/pix/webhook/pix?token=errado/pix', self::lote(1))->assertStatus(401);

        Event::assertNotDispatched(PixRecebido::class);
    }

    public function test_corpo_sem_pix_responde_200(): void
    {
        Event::fake();

        $this->postJson('/sicredi/pix/webhook?token=segredo-pix', [])->assertOk()->assertJson(['recebidos' => 0]);

        Event::assertNotDispatched(PixRecebido::class);
    }

    public function test_verificacao_mtls_pelo_proxy(): void
    {
        config()->set('sicredi-cobranca.pix.webhook.mtls_header', 'X-SSL-Client-Verify');
        Event::fake();

        $this->postJson('/sicredi/pix/webhook/pix?token=segredo-pix', self::lote(1))->assertStatus(403);
        $this->postJson('/sicredi/pix/webhook/pix?token=segredo-pix', self::lote(1), ['X-SSL-Client-Verify' => 'FAILED:unable to verify'])->assertStatus(403);
        $this->postJson('/sicredi/pix/webhook/pix?token=segredo-pix', self::lote(1), ['X-SSL-Client-Verify' => 'SUCCESS'])->assertOk();

        Event::assertDispatchedTimes(PixRecebido::class, 1);
    }
}
