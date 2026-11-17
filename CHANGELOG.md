# Changelog

Todas as mudanças relevantes deste pacote. O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)
e o versionamento segue [SemVer](https://semver.org/lang/pt-BR/).

## [1.0.0] - 2026-11-16

Primeira versão pública, validada em produção.

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

[1.0.0]: https://github.com/vagkaefer/sicredi-cobranca/releases/tag/v1.0.0
