<?php

declare(strict_types=1);

return [

    /*
    | Ambiente: "sandbox" (homologação) ou "producao".
    */
    'ambiente' => env('SICREDI_AMBIENTE', 'sandbox'),

    /*
    | x-api-key da aplicação criada no Portal do Desenvolvedor Sicredi.
    | Sandbox e produção usam chaves diferentes.
    */
    'api_key' => env('SICREDI_API_KEY'),

    /*
    | Código de acesso gerado no Internet Banking (Cobrança > Código de Acesso).
    | No sandbox o valor de teste é "teste123".
    */
    'codigo_acesso' => env('SICREDI_CODIGO_ACESSO'),

    // Cooperativa (4 dígitos), posto (2 dígitos) e código do beneficiário (5 dígitos).
    // Valores de teste do sandbox: 6789, 03 e 12345.
    'cooperativa' => env('SICREDI_COOPERATIVA'),
    'posto' => env('SICREDI_POSTO'),
    'codigo_beneficiario' => env('SICREDI_CODIGO_BENEFICIARIO'),

    // Timeouts das requisições, em segundos.
    'timeout' => (int) env('SICREDI_TIMEOUT', 30),
    'connect_timeout' => (int) env('SICREDI_CONNECT_TIMEOUT', 10),

    // Store de cache usado para guardar o token (null = store padrão).
    'cache_store' => env('SICREDI_CACHE_STORE'),

    'webhook' => [
        // Registra a rota POST que recebe os eventos de liquidação.
        'enabled' => (bool) env('SICREDI_WEBHOOK_ENABLED', false),
        'path' => env('SICREDI_WEBHOOK_PATH', 'sicredi/webhook'),
        'middleware' => ['api'],

        // Segredo exigido em cada chamada, no header abaixo ou no parâmetro ?token= da URL.
        // Vazio = aceita qualquer chamada (reconsulte o boleto antes de dar baixa).
        'token' => env('SICREDI_WEBHOOK_TOKEN'),
        'header' => env('SICREDI_WEBHOOK_HEADER', 'X-Sicredi-Token'),
    ],
];
