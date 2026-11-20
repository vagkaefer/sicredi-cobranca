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

    /*
    |--------------------------------------------------------------------------
    | API Pix (cobrança imediata) — experimental
    |--------------------------------------------------------------------------
    | Produto separado da Cobrança, com credenciais próprias (Client ID/Secret) e mTLS em todas as
    | chamadas. Veja a seção "Pix (cobrança imediata)" do README.
    */
    'pix' => [
        // "homologacao" ou "producao".
        'ambiente' => env('SICREDI_PIX_AMBIENTE', 'homologacao'),

        // URLs da API e do token. Em produção o padrão é https://api-pix.sicredi.com.br/api/v2 e
        // https://api-pix.sicredi.com.br/oauth/token. Em homologação são OBRIGATÓRIAS: o host não é
        // público e é informado pelo Sicredi ao liberar o acesso.
        'base_url' => env('SICREDI_PIX_BASE_URL'),
        'token_url' => env('SICREDI_PIX_TOKEN_URL'),

        // Credenciais geradas no Portal do Desenvolvedor para a aplicação da API Pix.
        'client_id' => env('SICREDI_PIX_CLIENT_ID'),
        'client_secret' => env('SICREDI_PIX_CLIENT_SECRET'),

        // mTLS: certificado assinado pelo Sicredi e a chave privada do CSR, ambos em PEM.
        // Aceita o caminho do arquivo ou o próprio conteúdo PEM (gravado em arquivo temporário 600).
        'certificado' => env('SICREDI_PIX_CERTIFICADO'),
        'certificado_senha' => env('SICREDI_PIX_CERTIFICADO_SENHA'),
        'chave' => env('SICREDI_PIX_CHAVE'),
        'chave_senha' => env('SICREDI_PIX_CHAVE_SENHA'),

        // Diretório dos arquivos temporários de certificado/chave (null = sys_get_temp_dir()/sicredi-pix).
        'diretorio_temporario' => env('SICREDI_PIX_DIRETORIO_TEMPORARIO'),

        // Chave Pix do recebedor (CNPJ, e-mail, telefone ou aleatória), usada nas cobranças e no webhook.
        'chave_pix' => env('SICREDI_PIX_CHAVE_PIX'),

        // Escopos pedidos no token, separados por espaço. A devolução usa pix.write (referência
        // OpenAPI); o guia do Sicredi cita pix.read. Se o token falhar por escopo, ajuste aqui.
        'escopos' => env('SICREDI_PIX_ESCOPOS', 'cob.write cob.read pix.read pix.write webhook.read webhook.write'),

        'timeout' => (int) env('SICREDI_PIX_TIMEOUT', 30),
        'connect_timeout' => (int) env('SICREDI_PIX_CONNECT_TIMEOUT', 10),

        // Store de cache usado para guardar o token (null = store padrão).
        'cache_store' => env('SICREDI_PIX_CACHE_STORE'),

        'webhook' => [
            // Registra as rotas POST {path} e {path}/pix que recebem os Pix.
            'enabled' => (bool) env('SICREDI_PIX_WEBHOOK_ENABLED', false),
            'path' => env('SICREDI_PIX_WEBHOOK_PATH', 'sicredi/pix/webhook'),
            'middleware' => ['api'],

            // Segredo exigido em cada chamada, no header abaixo ou no parâmetro ?token= da URL.
            'token' => env('SICREDI_PIX_WEBHOOK_TOKEN'),
            'header' => env('SICREDI_PIX_WEBHOOK_HEADER', 'X-Sicredi-Token'),

            // Sem token configurado o webhook responde 503. Só desligue se outra camada (ex.: mTLS
            // validado pelo proxy) garantir que as chamadas vêm do Sicredi.
            'exigir_token' => (bool) env('SICREDI_PIX_WEBHOOK_EXIGIR_TOKEN', true),

            // Opcional: header preenchido pelo proxy após validar o certificado do Sicredi (mTLS),
            // ex.: X-SSL-Client-Verify com valor SUCCESS. Vazio = não verifica.
            'mtls_header' => env('SICREDI_PIX_WEBHOOK_MTLS_HEADER'),
            'mtls_valor' => env('SICREDI_PIX_WEBHOOK_MTLS_VALOR', 'SUCCESS'),
        ],
    ],
];
