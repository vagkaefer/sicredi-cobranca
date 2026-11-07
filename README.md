# Sicredi Cobrança para Laravel

Pacote Laravel para a [API de Cobrança do Sicredi](https://developers.sicredi.com.br/public/docs/getting-started-billing):
registro de boleto e **boleto híbrido (boleto + Pix)**, PDF, consultas, conciliação de liquidados,
comandos de instrução (baixa, vencimento, juros, desconto, abatimento) e webhook de liquidação.

- Autenticação OAuth2 (password + refresh) com token em cache e lock contra corrida
- Normaliza os campos nos limites da API (documento, CEP, nome com 40 caracteres, mensagens...)
- Exceções tipadas por status HTTP
- Configuração por `.env` **ou** em tempo de execução (ex.: credenciais guardadas no banco)

Requer PHP 8.2+ e Laravel 11, 12 ou 13.

## Instalação

```bash
composer require vagkaefer/sicredi-cobranca
php artisan vendor:publish --tag=sicredi-cobranca-config   # opcional
```

```dotenv
SICREDI_AMBIENTE=sandbox            # sandbox | producao
SICREDI_API_KEY=                    # x-api-key do Portal do Desenvolvedor
SICREDI_CODIGO_ACESSO=              # sandbox: teste123
SICREDI_COOPERATIVA=                # 4 dígitos (sandbox: 6789)
SICREDI_POSTO=                      # 2 dígitos (sandbox: 03)
SICREDI_CODIGO_BENEFICIARIO=        # 5 dígitos (sandbox: 12345)
```

No sandbox, se cooperativa, posto, beneficiário e código de acesso ficarem vazios, o pacote usa os
valores de teste da documentação. Só a `x-api-key` é obrigatória.

## Uso

```php
use VagKaefer\SicrediCobranca\Data\{BoletoRequest, Pagador, Juros, Multa};
use VagKaefer\SicrediCobranca\Facades\SicrediCobranca;

$boleto = SicrediCobranca::criarBoleto(new BoletoRequest(
    dataVencimento: '2026-12-10',
    valor: 150.00,
    seuNumero: 'NF1234',                 // máx. 10
    idTituloEmpresa: 'PEDIDO-987',       // máx. 25, seu identificador interno
    pagador: new Pagador(
        documento: '12.345.678/0001-95',  // CPF ou CNPJ, com ou sem máscara
        nome: 'Cliente Ltda',
        endereco: 'Rua das Flores, 100',
        cidade: 'Porto Alegre',
        uf: 'RS',
        cep: '90000-000',
        email: 'financeiro@cliente.com',
    ),
    mensagens: ['Referente à NFS-e 1234'],
    multa: Multa::percentual(2),
    juros: Juros::percentualAoMes(1),
    validadeAposVencimento: 30,          // dias em que o QR Pix vale após o vencimento
));
// tipoCobranca padrão: HIBRIDO; especie padrão: DUPLICATA_SERVICO_INDICACAO

$boleto->nossoNumero;
$boleto->linhaDigitavel;
$boleto->pixCopiaECola();   // EMV do QR Code
$boleto->temPix();          // false se o híbrido voltou sem QR

file_put_contents('boleto.pdf', SicrediCobranca::pdf($boleto->linhaDigitavel));

$consulta = SicrediCobranca::consultarBoleto($boleto->nossoNumero);
$consulta->liquidado();     // situacao LIQUIDADO*
$consulta->dataLiquidacao;  // DateTimeImmutable|null

foreach (SicrediCobranca::liquidadosPorDia(now()) as $liquidacao) {
    // percorre todas as páginas
    $liquidacao->nossoNumero; $liquidacao->valorLiquidado; $liquidacao->tipoLiquidacao; // PIX, COMPE, REDE...
}

SicrediCobranca::baixar($nossoNumero);                       // 202: processado de forma assíncrona
SicrediCobranca::alterarVencimento($nossoNumero, '2026-12-20');
SicrediCobranca::alterarJuros($nossoNumero, 2.0);
SicrediCobranca::concederAbatimento($nossoNumero, 10.00);
SicrediCobranca::comando($nossoNumero, 'protesto');         // qualquer outro comando de instrução
```

### Juros, multa e Pix

No boleto **híbrido**, se o pedido levar `dataInicioJuros` ou `dataInicioMulta`, o Sicredi registra um
boleto tradicional, sem QR Code e sem avisar. Por isso o pacote **não envia essas datas** quando
`tipoCobranca` é `HIBRIDO`. Juros e multa continuam valendo e passam a contar depois do vencimento.
Com `TipoCobranca::Normal`, as datas são enviadas normalmente.

### Configuração em tempo de execução

```php
// AppServiceProvider::boot(): ex. credenciais guardadas no banco
app(\VagKaefer\SicrediCobranca\SicrediCobranca::class)
    ->resolverConfigUsing(fn () => [
        'ambiente' => setting('sicredi.ambiente'),
        'api_key' => setting('sicredi.api_key'),
        // ...mesmas chaves do config/sicredi-cobranca.php
    ]);

// Outra conta pontualmente
SicrediCobranca::usando(new Config(Ambiente::Producao, $apiKey, $codigoAcesso, '0101', '02', '12345'))
    ->criarBoleto($request);
```

### Erros

| Exceção | Quando |
|---|---|
| `AutenticacaoException` | 401/403, ou falha no login (x-api-key, código de acesso, cooperativa ou beneficiário) |
| `ValidacaoException` | 400/422: campo inválido ou regra de negócio (ex.: título já liquidado, híbrido não liberado) |
| `NaoEncontradoException` | 404 |
| `LimiteRequisicoesException` | 429: limite por segundo (registro: 20 TPS; consulta: 15 TPS) |
| `SicrediException` | Demais erros; `status` 0 = falha de conexão |

Todas têm `$e->status` e `$e->corpo` (a resposta original). Um 401 em chamada da API renova o token
e repete a chamada uma vez.

## Webhook de liquidação (só produção)

```dotenv
SICREDI_WEBHOOK_ENABLED=true
SICREDI_WEBHOOK_PATH=sicredi/webhook
SICREDI_WEBHOOK_TOKEN=um-segredo-longo
```

```php
SicrediCobranca::registrarWebhook('https://seu-app.com/sicredi/webhook?token=um-segredo-longo');

Event::listen(WebhookRecebido::class, function (WebhookRecebido $e) {
    ProcessarPagamento::dispatch($e->nossoNumero);   // responda rápido: o Sicredi espera 200 em até 10s
});
```

O Sicredi **não assina** as chamadas do webhook, e o formato do evento não é documentado. A rota
exige o token (no header `X-Sicredi-Token` ou em `?token=`), procura `nossoNumero` em qualquer nível
do corpo e dispara `WebhookRecebido`. **Reconsulte o boleto** com `consultarBoleto()` antes de dar
baixa financeira. Para garantir, rode também uma conciliação diária com `liquidadosPorDia()`.

Requisitos do endpoint: HTTPS com certificado válido (não autoassinado), TLS 1.2 e resposta em até 10s.

## Como obter acesso

### Homologação (sandbox)
1. Crie uma conta no [Portal do Desenvolvedor Sicredi](https://developers.sicredi.com.br) e uma
   aplicação com as APIs **Cobrança** e **Autenticação de Parceiros**.
2. Abra um chamado (Suporte → Abrir chamado) para liberar a `x-api-key` do sandbox. Ela aparece em
   Minhas Apps → (app) → Ver detalhes.
3. Use os valores de teste: cooperativa `6789`, posto `03`, beneficiário `12345`, código de acesso `teste123`.

O sandbox devolve dados fixos no PDF, na consulta e nos liquidados. Não tem webhook, nem consulta v2,
nem `/boletos/cadastrados`.

### Produção
1. Com a cooperativa: contrate a Cobrança na modalidade **API** (isso gera o Código do Beneficiário),
   assine o termo de adesão e, para Pix, peça a liberação da **modalidade híbrida** no Portal PJ.
2. No Portal do Desenvolvedor, crie a aplicação de produção (Cobrança + Autenticação de Parceiros) e
   abra um chamado para liberar a `x-api-key` de produção.
3. No Internet Banking, com o perfil **Master** da conta do convênio: Cobrança → Código de Acesso → Gerar.
4. Configure `SICREDI_AMBIENTE=producao` e as credenciais de produção.
5. Registre o webhook, se for usar.

## Testes

```bash
composer test
composer phpstan
composer cs-check
```

## Licença

MIT
