<?php

declare(strict_types=1);

namespace VagKaefer\SicrediCobranca\Tests\Feature;

use Illuminate\Support\Facades\Event;
use VagKaefer\SicrediCobranca\Events\WebhookRecebido;
use VagKaefer\SicrediCobranca\Tests\TestCase;

class WebhookTest extends TestCase
{
    public function test_recusa_sem_token(): void
    {
        Event::fake();

        $this->postJson('/sicredi/webhook', ['nossoNumero' => '1'])->assertStatus(401);
        $this->postJson('/sicredi/webhook?token=errado', ['nossoNumero' => '1'])->assertStatus(401);

        Event::assertNotDispatched(WebhookRecebido::class);
    }

    public function test_aceita_token_no_header_ou_na_url_e_extrai_dados(): void
    {
        Event::fake();

        $this->postJson('/sicredi/webhook', [
            'agencia' => '6789',
            'movimento' => 'LIQUIDACAO_PIX',
            'titulo' => ['nossoNumero' => '211001292', 'idTituloEmpresa' => 'CG7'],
        ], ['X-Sicredi-Token' => 'segredo'])->assertOk()->assertJson(['ok' => true]);

        $this->postJson('/sicredi/webhook?token=segredo', [['nossoNumero' => 'A'], ['nossoNumero' => 'B']])->assertOk();

        Event::assertDispatched(WebhookRecebido::class, fn(WebhookRecebido $e) => $e->nossoNumero === '211001292' && $e->evento === 'LIQUIDACAO_PIX' && $e->idTituloEmpresa === 'CG7');
        Event::assertDispatchedTimes(WebhookRecebido::class, 3);
    }
}
