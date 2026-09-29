# Banco Inter — implementação do provider `inter`

Revisado em 29/09/2026 contra o Portal do Desenvolvedor e o SDK Java oficial do Banco Inter.

## Escopo implementado

- OAuth 2.0 `client_credentials` com mTLS, cache criptografado por scope e renovação por `expires_in`.
- PIX imediato em `PUT /pix/v2/cob/{txid}` (`cob.write`).
- PIX com vencimento em `PUT /pix/v2/cobv/{txid}` (`cobv.write`).
- Consulta de Cob/CobV com os scopes de leitura correspondentes.
- Cancelamento de Cob/CobV por `PATCH`, usando status `REMOVIDA_PELO_USUARIO_RECEBEDOR`.
- Boleto com PIX em `POST /cobranca/v3/cobrancas` (`boleto-cobranca.write`).
- Consulta, cancelamento e PDF do boleto pelos endpoints filhos do `codigoSolicitacao`.
- Webhook PIX e cobrança pela rota comum `/api/webhooks/payment/inter/{company_id}`.
- Conciliação com preservação de `txid`, `codigoSolicitacao`/`external_id`, status original e `origemRecebimento` (`PIX` ou `BOLETO`).

API Banking de saldo, extrato e pagamentos não foi incluída.

## Configuração

Cadastre Client ID, Client Secret, chave PIX e, se aplicável a múltiplas contas, o número usado no header `x-conta-corrente`. Envie PFX/P12 ou o par CRT/PEM + KEY pelo formulário da conta. Todo material é armazenado criptografado; arquivos PEM temporários são criados apenas durante a chamada e removidos em `finally`.

Produção usa `https://cdpj.partners.bancointer.com.br`; sandbox usa `https://cdpj-sandbox.partners.uatinter.co`. O token é solicitado em `/oauth/v2/token`, com Client ID e Client Secret no corpo form-urlencoded, conforme o SDK oficial.

## Webhook e segurança

O Inter autentica callbacks por certificado cliente. O terminador TLS deve validar esse certificado usando a CA oficial fornecida pelo banco e encaminhar `SSL_CLIENT_VERIFY=SUCCESS` ao PHP. A aplicação rejeita callbacks sem essa confirmação e usa a fila, deduplicação e auditoria comuns. Não configure cabeçalhos dessa variável a partir da internet.

## Pendências de confirmação prática

PENDENTE DE CONFIRMAÇÃO em sandbox/homologação: autenticação real, permissões liberadas na integração, emissão e liquidação de Cob/CobV, processamento assíncrono do boleto, disponibilidade imediata do PDF, callback real, consulta e cancelamento. Essas validações exigem credenciais e certificado emitidos para uma conta PJ e não podem ser simuladas como concluídas.
