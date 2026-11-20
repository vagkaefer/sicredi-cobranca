# Changelog

Todas as mudanças relevantes deste pacote. O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)
e o versionamento segue [SemVer](https://semver.org/lang/pt-BR/).

## [Unreleased]

## [1.1.0] - 2026-11-20

### Segurança
- Webhook da Cobrança: quando a configuração não pode ser lida e não há token no arquivo de config,
  responde 503 em vez de aceitar a chamada sem token.

### Experimental
- Módulo Pix (cobrança imediata) para a API Pix do Sicredi, no padrão da API Pix do Banco Central.
  Construído pela documentação e testado só com respostas simuladas: fica experimental até a validação
  em homologação e pode mudar numa versão menor. A API de Cobrança não muda.
  - Configuração na seção `pix` (`PixConfig`): `base_url` e `token_url` obrigatórias em homologação
    (host não público), certificado e chave PEM por caminho ou conteúdo (arquivo temporário 600),
    detecção de certificado em DER e de certificado e chave trocados, e `impressaoDigital()`.
  - mTLS (`cert`/`ssl_key`) em todas as chamadas, inclusive a do token.
  - Token `client_credentials` (Basic) em cache até `expires_in` - 30 s, com lock; a chave do cache
    inclui o certificado. Um 401 renova o token e repete a chamada uma vez.
  - `SicrediPix` (facade `SicrediPix`): `criarCobranca()` (txid gerado ou informado e validado),
    `consultarCobranca()`, `revisarCobranca()`, `cancelarCobranca()`, `listarCobrancas()`,
    `pixRecebidos()` (paginado, datas em UTC), `consultarPix()`, `devolver()`, `consultarDevolucao()`,
    `configurarWebhook()`, `consultarWebhook()` e `removerWebhook()`.
  - Erros RFC 7807 em `PixException` e subclasses por status, com `correlationId` (também na
    mensagem) e `violacoes`.
  - Webhook nas rotas `{path}` e `{path}/pix`, com token, verificação opcional de mTLS feita pelo
    proxy e o evento `PixRecebido` por item do lote. Sem token configurado (ou com a configuração
    com erro) responde 503; a exigência só cai com `webhook.exigir_token=false`. O token pode vir de
    `resolverWebhookTokenUsing()`, sem depender do certificado.
  - Escopo da devolução: `pix.write` por padrão (referência OpenAPI); o guia cita `pix.read`.

## [1.0.0] - 2026-11-16

Primeira versão pública, validada em produção. Requer PHP 8.2+ e Laravel 12 ou 13.

### Adicionado
- Autenticação OAuth2 (password + refresh_token), com o token em cache e lock contra autenticações paralelas.
  Um 401 renova o token e repete a chamada uma vez.
- Registro de boleto `NORMAL` e `HIBRIDO` (boleto + Pix), com pagador, juros, multa, até 3 descontos,
  mensagens, informativos e validade do QR após o vencimento. Os campos são normalizados nos limites da API.
- Em `HIBRIDO`, as datas de início de juros e multa não são enviadas: com elas o Sicredi registra um
  boleto sem QR Code e sem avisar.
- PDF do boleto, consulta por nosso número, títulos cadastrados e liquidados por dia, com paginação automática.
- Comandos de instrução: baixa, vencimento, juros, desconto, seu número, abatimento e qualquer outro via `comando()`.
- Exceções por status HTTP (`AutenticacaoException`, `ValidacaoException`, `NaoEncontradoException`,
  `LimiteRequisicoesException`), com o corpo original da resposta.
- Configuração por `.env` ou em tempo de execução (`resolverConfigUsing()` / `usando()`).
- Situações do boleto normalizadas (`emAberto()`, `liquidado()`, `baixado()`), incluindo `EM CARTEIRA PIX`.

### Experimental
- Webhook de liquidação: contratação, consulta e atualização do contrato, e a rota que recebe os eventos e
  dispara `WebhookRecebido`. O formato do evento não é documentado pelo Sicredi e ainda não foi validado
  com eventos reais, então pode mudar numa versão menor.

[Unreleased]: https://github.com/vagkaefer/sicredi-cobranca/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/vagkaefer/sicredi-cobranca/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/vagkaefer/sicredi-cobranca/releases/tag/v1.0.0
