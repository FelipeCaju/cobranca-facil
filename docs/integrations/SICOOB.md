# Sicoob — implementação do provider `sicoob`

Revisado em 29/09/2026 diretamente no Portal Developers Sicoob.

## Produtos e versões

- PIX Recebimentos: `https://api.sicoob.com.br/pix/api/v2`.
- Cobrança Bancária vigente: `https://api.sicoob.com.br/cobranca-bancaria/v3`.
- Sandbox PIX: `https://sandbox.sicoob.com.br/sicoob/sandbox/pix/api/v2`.
- Sandbox Cobrança: `https://sandbox.sicoob.com.br/sicoob/sandbox/cobranca-bancaria/v3`.
- OAuth produção: `https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token`.

Não foram implementados Conta Corrente, PIX Pagamentos, Cobrança Bancária Pagamentos, TED/SPB, investimentos ou qualquer saída de dinheiro.

## Segurança e autenticação

O Sicoob exige certificado A1 e-CPF/e-CNPJ ICP-Brasil com uso avançado `Autenticação de Cliente (1.3.6.1.5.5.7.3.2)`. O mTLS é usado na geração do token e no consumo das APIs protegidas. O provider reutiliza CertificateManager, OAuthTokenManager, CredentialVault e BankHttpClient.

Em produção, PIX e Cobrança podem usar credenciais específicas e sempre possuem scopes configurados separadamente (`pix_scopes` e `billing_scopes`). O código não inventa scopes. No sandbox, o Portal entrega um Access Token de teste; ele é armazenado criptografado como `sandbox_access_token`. Todas as requisições enviam Bearer Token e header `client_id`.

## Configuração específica

Em `provider_config`: chave PIX, scopes por produto, número do cliente, modalidade, conta corrente, contrato de cobrança, espécie de documento, indicadores de emissão/distribuição e liberação do boleto híbrido. Em credenciais criptografadas: Client ID/Secret geral ou específicos por produto e token do sandbox. Não foram criadas colunas globais para esses dados.

## Fluxos implementados

- Cob e CobV: criar, consultar, obter imagem QR e cancelar por revisão para `REMOVIDA_PELO_USUARIO_RECEBEDOR`.
- Boleto v3: emitir, consultar por nosso número, comandar baixa e emitir segunda via.
- PDF: `gerarPdf=true` na emissão e consulta de `/boletos/segunda-via` na reconciliação.
- Híbrido: `codigoCadastrarPIX=1` somente quando o produto contratado estiver explicitamente habilitado.
- Conciliação: preserva nosso número, txid, external_id, linha digitável, PDF, QR/PIX copia e cola e status remoto.
- Webhook: recebe pela rota comum `/api/webhooks/payment/sicoob/{company_id}`, deduplica, audita e processa na fila persistente.

## Pendências reais

PENDENTE DE CONFIRMAÇÃO no sandbox/homologação: scopes exatos concedidos ao aplicativo, autenticação real, obrigatoriedade de nosso número na modalidade contratada, emissão híbrida, formatos reais dos callbacks, autenticação mTLS do servidor receptor, liquidação, consulta, segunda via e baixa. Esses pontos precisam das credenciais, certificado, contrato e dados de teste do cooperado e não são marcados como validados apenas por testes locais.
