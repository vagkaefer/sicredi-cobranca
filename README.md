# Sicredi Cobrança para Laravel

[![CI](https://github.com/vagkaefer/sicredi-cobranca/actions/workflows/ci.yml/badge.svg)](https://github.com/vagkaefer/sicredi-cobranca/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/vagkaefer/sicredi-cobranca.svg)](https://packagist.org/packages/vagkaefer/sicredi-cobranca)
[![Licença](https://img.shields.io/packagist/l/vagkaefer/sicredi-cobranca.svg)](LICENSE)

Pacote Laravel para a [API de Cobrança do Sicredi](https://developers.sicredi.com.br/public/docs/getting-started-billing):
registro de boleto e **boleto híbrido (boleto + Pix)**, PDF, consultas, conciliação de liquidados,
comandos de instrução (baixa, vencimento, juros, desconto, abatimento) e webhook de liquidação
(experimental).

Inclui também um módulo **experimental** para a [API Pix do Sicredi](https://developers.sicredi.com.br/public/docs/getting-started-pix)
(cobrança imediata com QR Code, Pix recebidos, devolução e webhook), ainda não validado em
homologação. Veja [Pix (cobrança imediata)](#pix-cobrança-imediata--experimental).

Validado em produção: autenticação, boleto híbrido com multa/juros, PDF, consulta, baixa e
conciliação de pagamentos via Pix.

- Autenticação OAuth2 (password + refresh) com token em cache e lock contra corrida
- Normaliza os campos nos limites da API (documento, CEP, nome com 40 caracteres, mensagens...)
- Exceções tipadas por status HTTP
- Configuração por `.env` **ou** em tempo de execução (ex.: credenciais guardadas no banco)

Requer PHP 8.2+ e Laravel 12 ou 13 (Laravel 13 exige PHP 8.3+).

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
use VagKaefer\SicrediCobranca\Config;
use VagKaefer\SicrediCobranca\Enums\Ambiente;
use VagKaefer\SicrediCobranca\Facades\SicrediCobranca;

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

## Particularidades da produção

O sandbox devolve dados fixos e não mostra estes comportamentos, observados em produção:

- **PDF logo após o registro:** nos primeiros segundos depois de `criarBoleto()`, `pdf()` lança
  `SicrediException` com **HTTP 406**: o PDF ainda não foi gerado. Baixe o PDF numa fila, com nova
  tentativa:

  ```php
  class BaixarPdfDoBoleto implements ShouldQueue
  {
      public int $tries = 6;
      public array $backoff = [30, 60, 120, 300, 900];

      public function __construct(public string $linhaDigitavel) {}

      public function handle(): void
      {
          Storage::put("boletos/{$this->linhaDigitavel}.pdf", SicrediCobranca::pdf($this->linhaDigitavel));
      }
  }
  ```

- **Situação em aberto do híbrido:** vem como `EM CARTEIRA PIX` (não só `EM CARTEIRA`). Use
  `$consulta->emAberto()`, `liquidado()` e `baixado()`, que comparam pelo início e ignoram acentos.
- **Hora do pagamento:** `liquidadosPorDia()` traz só a data (`2026-11-16 00:00:00`). A data e hora
  exatas, com fuso (`2026-11-16T22:01:43-03:00`), vêm de `consultarBoleto($nossoNumero)->dataLiquidacao`.
- **Baixa:** `baixar()` responde 202 com `statusComando` `MOVIMENTO_ENVIADO`; a situação passa para
  `BAIXADO POR SOLICITACAO` em poucos segundos.
- **Só existem em produção:** webhook, `cadastrados()` e a consulta v2.

## Webhook de liquidação (experimental, só produção)

> **Experimental.** O Sicredi não documenta o formato do evento enviado ao webhook e não assina as
> chamadas, e esta parte ainda não foi validada com eventos reais de produção. Ela pode mudar numa
> versão menor. Use o webhook só como aviso para reconsultar o boleto e mantenha uma conciliação
> periódica com `liquidadosPorDia()` como fonte de verdade.

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

## Pix (cobrança imediata) — experimental

> **Experimental até a validação em homologação.** O módulo Pix foi construído pela documentação do
> Sicredi e pelo padrão da API Pix do Banco Central, e testado só com respostas simuladas. Ele ainda
> não foi chamado contra o Sicredi e pode mudar numa versão menor. Os pontos em aberto estão em
> [Pontos a confirmar](#pontos-a-confirmar-em-homologação).

A API Pix é um produto **separado** da API de Cobrança: tem credenciais próprias (Client ID/Secret),
OAuth2 `client_credentials` e **mTLS** em todas as chamadas, inclusive a do token. Ela cria a
**cobrança imediata** (`cob`): um QR Code com validade em segundos, sem boleto. Para cobrar com
vencimento, continue usando o boleto híbrido.

### Configuração

```dotenv
SICREDI_PIX_AMBIENTE=homologacao        # homologacao | producao
# Em produção as URLs têm padrão (https://api-pix.sicredi.com.br/api/v2 e .../oauth/token).
# Em homologação são OBRIGATÓRIAS: o host não é público e é informado pelo Sicredi.
SICREDI_PIX_BASE_URL=
SICREDI_PIX_TOKEN_URL=
SICREDI_PIX_CLIENT_ID=
SICREDI_PIX_CLIENT_SECRET=
SICREDI_PIX_CERTIFICADO=/caminho/certificado.pem   # ou o conteúdo PEM
SICREDI_PIX_CHAVE=/caminho/chave.pem               # chave privada do CSR, ou o conteúdo PEM
SICREDI_PIX_CHAVE_SENHA=                           # se a chave for protegida
SICREDI_PIX_CHAVE_PIX=12345678000195               # a chave Pix do recebedor
# SICREDI_PIX_ESCOPOS="cob.write cob.read pix.read pix.write webhook.read webhook.write"
```

- `SICREDI_PIX_CHAVE` é o arquivo da **chave privada**; `SICREDI_PIX_CHAVE_PIX` é a **chave Pix** que
  recebe os pagamentos.
- Certificado e chave podem vir pelo caminho ou pelo conteúdo PEM (ex.: guardado no banco). O conteúdo
  é gravado em `sys_get_temp_dir()/sicredi-pix` (ou em `diretorio_temporario`) com permissão 600,
  porque o Guzzle só aceita caminho. Guarde os arquivos fora do repositório e do diretório público.
- A configuração é validada na primeira operação: falta de URL em homologação, arquivo inexistente,
  certificado em DER e certificado e chave trocados geram `InvalidArgumentException` com a causa.
- O token fica em cache até `expires_in` menos 30 s, com lock contra autenticações paralelas. O Sicredi
  avisa que autenticar a cada chamada pode **bloquear o cliente temporariamente**.

Em tempo de execução, como na Cobrança:

```php
app(\VagKaefer\SicrediCobranca\Pix\SicrediPix::class)
    ->resolverConfigUsing(fn () => [
        'ambiente' => setting('pix.ambiente'),
        'client_id' => setting('pix.client_id'),
        // ...mesmas chaves da seção "pix" do config/sicredi-cobranca.php
    ]);

SicrediPix::usando(PixConfig::fromArray([...]))->consultarCobranca($txid);
```

### Uso

```php
use VagKaefer\SicrediCobranca\Facades\SicrediPix;
use VagKaefer\SicrediCobranca\Pix\Data\{CobRequest, Devedor};
use VagKaefer\SicrediCobranca\Pix\Enums\NaturezaDevolucao;

// txid: [a-zA-Z0-9]{26,35}. Gere e GUARDE antes de chamar: repetir o PUT com o mesmo txid
// não cria outra cobrança. Sem txid, o pacote gera um de 32 caracteres.
$txid = SicrediPix::gerarTxid();

$cob = SicrediPix::criarCobranca(new CobRequest(
    valor: 150.00,                        // enviado como "150.00"
    expiracao: 3600,                      // segundos (padrão: 1 hora)
    devedor: new Devedor('123.456.789-09', 'Fulano de Tal'),   // CPF ou CNPJ; nome até 200
    solicitacaoPagador: 'Fatura 123',     // até 140
    infoAdicionais: ['Pedido' => '987'],  // nome até 50, valor até 200
), $txid);

$cob->pixCopiaECola;   // EMV para o QR Code / copia e cola
$cob->location;
$cob->expiraEm();      // criação + expiração
$cob->ativa();

$cob = SicrediPix::consultarCobranca($txid);
$cob->concluida();     // paga
$cob->pagamentos;      // list<PixRecebido>: endToEndId, valor, horario, infoPagador...
$cob->expirada();      // o status pode continuar ATIVA depois de expirar

SicrediPix::revisarCobranca($txid, ['valor' => ['original' => '120.00']]);  // PATCH, formato da API
SicrediPix::cancelarCobranca($txid);      // PATCH só com status REMOVIDA_PELO_USUARIO_RECEBEDOR

foreach (SicrediPix::listarCobrancas('2026-11-01', '2026-11-30 23:59:59', ['status' => 'ATIVA']) as $cob) {
    // percorre todas as páginas
}

// Pix recebidos. Datas sem fuso são lidas em America/Sao_Paulo e enviadas em UTC (RFC 3339).
foreach (SicrediPix::pixRecebidos(now()->subDay(), now(), ['txIdPresente' => true]) as $pix) {
    $pix->endToEndId; $pix->txid; $pix->valor; $pix->horario; $pix->devolucoes;
}
SicrediPix::consultarPix($e2eid);

// Devolução. O id ([a-zA-Z0-9]{1,35}) é seu: repetir com o mesmo id não devolve duas vezes.
$dev = SicrediPix::devolver($e2eid, 'DEV123', 150.00, NaturezaDevolucao::Original, 'Desistência');
$dev->emProcessamento();   // acompanhe com consultarDevolucao($e2eid, 'DEV123')
$dev = SicrediPix::consultarDevolucao($e2eid, 'DEV123');
$dev->devolvido(); $dev->naoRealizado(); $dev->motivo;
```

### Erros

A API responde no formato RFC 7807 (`application/problem+json`). Toda falha vira `PixException`, que
estende `SicrediException`:

| Exceção | Quando |
|---|---|
| `PixAutenticacaoException` | 401/403, ou falha ao obter o token (credenciais, escopo, certificado) |
| `PixValidacaoException` | 400/422: veja `$e->violacoes` (`razao`, `propriedade`) |
| `PixNaoEncontradoException` | 404 |
| `PixLimiteRequisicoesException` | 429 |
| `PixException` | Demais erros; `status` 0 = falha de conexão ou de TLS |

Além de `status` e `corpo`, trazem `correlationId`, `violacoes`, `tipo` (`type`), `titulo` (`title`) e
`detalhe` (`detail`). O **`correlationId` vai na mensagem**, porque é o que o suporte do Sicredi pede
para investigar uma chamada.

### Escopos

O token pede os escopos de `SICREDI_PIX_ESCOPOS`. Padrão: `cob.write cob.read pix.read pix.write
webhook.read webhook.write`.

**Divergência na documentação:** para a devolução, a referência OpenAPI
(`PUT /pix/{e2eid}/devolucao/{id}`) exige `pix.write`, mas o guia de introdução cita `pix.read`. O
pacote pede `pix.write` por padrão. Se o token falhar por escopo não liberado, ajuste a lista.

### Webhook Pix (experimental)

```dotenv
SICREDI_PIX_WEBHOOK_ENABLED=true
SICREDI_PIX_WEBHOOK_PATH=sicredi/pix/webhook
SICREDI_PIX_WEBHOOK_TOKEN=um-segredo-longo
```

```php
SicrediPix::configurarWebhook('https://seu-app.com/sicredi/pix/webhook?token=um-segredo-longo');
SicrediPix::consultarWebhook();   // WebhookPix: webhookUrl, chave, criacao
SicrediPix::removerWebhook();

Event::listen(\VagKaefer\SicrediCobranca\Events\PixRecebido::class, function ($e) {
    ConferirPix::dispatch($e->pix->txid, $e->pix->endToEndId);   // processe numa fila
});
```

- Pelo padrão do Banco Central, o PSP chama **`{webhookUrl}/pix`** com `{"pix": [...]}`. O pacote
  registra as duas rotas, `POST {path}` e `POST {path}/pix`, e dispara um `PixRecebido` por item.
  Responde 200 mesmo sem itens (ex.: teste de alcance ao configurar o webhook).
- O token é exigido no header `X-Sicredi-Token` ou em `?token=`. Se o PSP só concatenar `/pix` no fim
  da URL registrada, o token chega como `um-segredo-longo/pix`; isso também é aceito.
- **Sem token configurado, o webhook responde 503** e não processa nada. Isso também vale quando a
  configuração Pix está com erro (ex.: certificado ausente) e não há token no arquivo de config. Só
  desligue a exigência (`SICREDI_PIX_WEBHOOK_EXIGIR_TOKEN=false`) se o mTLS validado pelo proxy
  garantir a origem das chamadas.
- Para guardar o token fora do `.env` (ex.: no banco), sem depender da leitura do certificado:

  ```php
  app(\VagKaefer\SicrediCobranca\Pix\SicrediPix::class)
      ->resolverWebhookTokenUsing(fn () => setting('sicredi.pix.webhook_token'));
  ```
- **mTLS:** o Sicredi chama o webhook com certificado de cliente, que deve ser validado no servidor
  web, não no PHP. A CA usada pelo Sicredi ainda está **a confirmar**. Com o proxy validando, ative a
  conferência do header que ele repassa:

  ```nginx
  ssl_client_certificate /etc/nginx/ca-sicredi-pix.pem;   # CA a confirmar com o Sicredi
  ssl_verify_client optional;
  location /sicredi/pix/webhook {
      proxy_set_header X-SSL-Client-Verify $ssl_client_verify;
      # ...
  }
  ```

  ```dotenv
  SICREDI_PIX_WEBHOOK_MTLS_HEADER=X-SSL-Client-Verify   # exige o valor SICREDI_PIX_WEBHOOK_MTLS_VALOR (SUCCESS)
  ```

- **Reconsulte antes de dar baixa:** use `consultarCobranca($e->pix->txid)` e confira `concluida()`.
  Mantenha também uma conciliação periódica com `pixRecebidos()`.

### Como obter acesso à API Pix

1. **Adesão:** com a cooperativa, contrate o Pix na conta e cadastre a **chave Pix** que vai receber
   (ex.: o CNPJ).
2. No [Portal do Desenvolvedor](https://developer.sicredi.com.br), abra o chamado **"Acesso à API
   Pix"**.
3. **CSR:** gere a chave privada e o pedido de certificado (RSA 2048) e envie o CSR pelo Portal. Guarde
   a chave privada: o certificado assinado só funciona com ela.

   ```bash
   openssl req -new -newkey rsa:2048 -keyout chave.pem -out pedido.csr   # siga os dados pedidos pelo Portal
   ```

4. **Certificado:** depois da validação do CSR, baixe o certificado assinado no Portal.
5. **Client ID/Secret:** gere as credenciais da aplicação no Portal.
6. **Homologação:** abra o chamado "Suporte Técnico API Pix" → **"Cadastro Ambiente Homologação API
   PIX"** com o CNPJ. O Sicredi informa o host de homologação, que vai em `SICREDI_PIX_BASE_URL` e
   `SICREDI_PIX_TOKEN_URL`.

**Certificado em DER → PEM:** o pacote exige PEM. Se o arquivo baixado for binário (DER):

```bash
openssl x509 -inform der -in certificado.cer -out certificado.pem
openssl rsa -inform der -in chave.der -out chave.pem     # só se a chave também estiver em DER
```

**Causas comuns de 403** (ou erro de TLS):

- **Certificado e chave trocados** ou chave que não é a do CSR. Confira se correspondem:

  ```bash
  openssl x509 -noout -modulus -in certificado.pem | openssl sha256
  openssl rsa  -noout -modulus -in chave.pem       | openssl sha256   # os dois hashes devem ser iguais
  ```

- **Certificado em DER** em vez de PEM (o pacote avisa antes de chamar).
- **Thumbprint:** o certificado usado não é o cadastrado na aplicação do Portal (ex.: depois de uma
  renovação). Compare a impressão digital com a do Portal:
  `SicrediPix::config()->impressaoDigital()` (SHA-1; `'sha256'` também é aceito) ou
  `openssl x509 -noout -fingerprint -sha1 -in certificado.pem`.
- **Escopo** não liberado para a aplicação (veja [Escopos](#escopos)).

**Renovação do certificado:** o certificado tem validade. Antes de vencer, gere um novo CSR, envie pelo
Portal, baixe o novo certificado e troque os arquivos (certificado e, se gerou outra, a chave). A
configuração é lida a cada operação, e o token em cache está ligado ao certificado: com o arquivo novo,
o próximo uso autentica de novo. Se o Portal pedir, atualize a aplicação com o novo certificado.

### Pontos a confirmar em homologação

- Host de homologação e se ele usa o mesmo certificado de produção.
- Se o webhook chama `{webhookUrl}/pix`, se aceita URL com `?token=` e como concatena o sufixo.
- CA do mTLS do webhook e política de reentrega.
- Escopo da devolução (`pix.read` ou `pix.write`).
- Formato exato das respostas do Sicredi (datas, `location`, `pixCopiaECola`, paginação de `/pix` e
  `/cob`) e do corpo de erro RFC 7807, inclusive o `correlationId`.
- Resposta do `PUT /webhook/{chave}` e do `DELETE` (200 ou 204).

## Como obter acesso (Cobrança)

Sandbox e produção usam **aplicações separadas** no Portal do Desenvolvedor, cada uma com sua
`x-api-key`. Com mais de um código de beneficiário, cada um precisa do seu par de aplicações.

### Homologação (sandbox)
1. Crie uma conta profissional no [Portal do Desenvolvedor Sicredi](https://developer.sicredi.com.br)
   e, em Minha Conta → Minhas Apps → Criar nova aplicação, uma app (ex.: `Api Cobrança <cooperativa>
   <beneficiário> Sandbox`) com **API AUTH – OPENAPI – PARCEIROS** e **API COBRANÇA BOLETO**.
2. Abra um chamado em Suporte → Abra um chamado: assunto "API Cobrança Boletos", motivo "Solicitar
   Access Token", ambiente "Sandbox", informando o Client ID da app. Depois de resolvido, a
   `x-api-key` aparece em Minhas Apps → (app) → Ver detalhes.
3. Use os valores de teste: cooperativa `6789`, posto `03`, beneficiário `12345`, código de acesso `teste123`.

O sandbox devolve dados fixos no PDF, na consulta e nos liquidados. Não tem webhook, nem consulta v2,
nem `/boletos/cadastrados`.

### Produção
1. Com a cooperativa: contrate a Cobrança na modalidade **API** (isso gera o Código do Beneficiário),
   assine o termo de adesão e, para Pix, peça a liberação da **modalidade híbrida**.
2. No Portal do Desenvolvedor, crie a app de produção (ex.: `Api Cobrança <cooperativa> <beneficiário>
   Produção`) com as mesmas duas APIs e abra o mesmo chamado, com ambiente "Produção" e o Client ID da
   app nova.
3. No Internet Banking, com o perfil **Master** da conta do convênio: Cobrança → Código de Acesso →
   Gerar. O menu só aparece depois que a modalidade API está habilitada.
4. Cooperativa, posto e beneficiário estão em qualquer boleto da conta, no campo "Agência / Código do
   Beneficiário", no formato `CCCC.PP.BBBBB`.
5. Configure `SICREDI_AMBIENTE=producao` e as credenciais de produção. Com a sandbox `x-api-key` em
   produção, a autenticação responde 403 "Access denied for this environment".

Suporte do Sicredi: chamados no portal ou cobrancaonline@sicredi.com.br (sandbox); para produção e
boletos já registrados, a central de Cobrança (3003-4770 / 0800-724-4770).

## Testes

```bash
composer test
composer phpstan
composer cs-check
```

## Versionamento

Segue [SemVer](https://semver.org/lang/pt-BR/). As mudanças de cada versão estão no
[CHANGELOG](CHANGELOG.md). Partes marcadas como experimentais podem mudar em versões menores.

## Licença

MIT
