# Registro mestre das integrações bancárias

Atualizado em 29/09/2026. Providers Banco Inter, Sicoob, Sicredi e Santander implementados localmente; homologações remotas permanecem pendentes de credenciais e certificados reais.

Este é o **único arquivo de acompanhamento por banco**. Para cada novo provider, devem ser registrados aqui: documentação oficial consultada, produtos e versões, autenticação, configuração, operações implementadas, dados preservados, testes executados, restrições, pendências de sandbox/homologação e estado de produção. Um item local concluído não significa homologação bancária.

## Resumo executivo

| Provider | Código local | Testes locais | Sandbox real | Homologação | Produção |
|---|---:|---:|---:|---:|---:|
| Asaas | Sim | Parcial | Pendente de credencial | Pendente | Pendente |
| Mercado Pago | Sim | Parcial | Pendente de credencial | Pendente | Pendente |
| Banco Inter | Sim | Sim | Pendente de credencial/certificado | Pendente | Pendente |
| Sicoob | Sim | Sim | Pendente de credencial/certificado | Pendente | Pendente |
| Sicredi | Sim | Sim | Parcial e pendente de credenciais | Pendente | Pendente |
| Santander | Sim | Sim | Pendente de credencial/certificado | Pendente | Pendente |
| BB, Itaú, Caixa, C6 e Bradesco | Não | Não | Não iniciado | Não iniciado | Não iniciado |

## Infraestrutura bancária comum

- [x] Credenciais extensíveis e criptografadas
- [x] Configuração JSON específica por provider
- [x] Certificate Manager (PEM, CRT/KEY e PFX/P12)
- [x] Metadata de validade e fingerprint de certificado
- [x] OAuth Token Manager com `expires_in` e cache criptografado
- [x] HTTP Client com TLS, mTLS, Bearer, Basic, timeout e retry controlado
- [x] Estratégia compartilhada de referência, txid e idempotência
- [x] Provider metadata e capabilities centralizadas
- [x] Formulário orientado por metadata
- [x] Contrato complementar de teste de conexão não destrutivo
- [x] Webhook comum, assinado, deduplicado, auditado e assíncrono
- [x] Preservação de status/evento/origem/referências remotas
- [x] Compatibilidade com Asaas e Mercado Pago
- [x] Migration retrocompatível
- [x] Testes compartilhados de infraestrutura

## Checklist por provider

Os itens permanecem desmarcados até a implementação e homologação de cada instituição.

### Inter
- [x] documentação oficial e SDK oficial revisados
- [x] cadastro/credenciais criptografadas
- [x] autenticação OAuth `client_credentials` por scope usando OAuthTokenManager
- [x] certificado mTLS usando CertificateManager
- [ ] sandbox
- [x] PIX imediato (Cob) implementado localmente
- [x] PIX com vencimento (CobV) implementado localmente
- [x] boleto com PIX implementado localmente
- [x] webhook implementado na infraestrutura comum, com exigência de mTLS no servidor
- [x] consulta implementada localmente
- [x] cancelamento implementado localmente
- [x] PDF e PIX copia e cola implementados localmente
- [x] conciliação preserva `txid`, `external_id`, status original e `origemRecebimento`
- [x] testes locais de contrato e normalização
- [ ] autenticação validada com credencial real
- [ ] PIX, boleto, webhook, consulta e cancelamento validados no sandbox
- [ ] homologação
- [ ] produção

**Estado:** código local concluído, provider ainda **não homologado/concluído operacionalmente**. Os itens de sandbox não podem ser marcados sem Client ID, Client Secret, certificado/chave e conta de teste fornecidos pelo titular.

**Mapeamento:** vencimento futuro usa CobV (`/pix/v2/cobv/{txid}`); vencimento no dia usa Cob (`/pix/v2/cob/{txid}`). Boleto híbrido usa `/cobranca/v3/cobrancas`, preservando liquidação `PIX` ou `BOLETO`.

**Webhook:** o Apache/reverse proxy de produção deve validar o certificado cliente com a CA oficial do Inter e repassar `SSL_CLIENT_VERIFY=SUCCESS` ao PHP. O header `x-conta-corrente` é cruzado com a conta configurada quando ambos estiverem presentes.

**Produtos, versões e endpoints usados:** PIX Cob/CobV em `/pix/v2`; boleto híbrido em `/cobranca/v3/cobrancas`; OAuth em `/oauth/v2/token`. Produção usa `https://cdpj.partners.bancointer.com.br` e sandbox usa `https://cdpj-sandbox.partners.uatinter.co`.

**Configuração:** Client ID, Client Secret, chave PIX, conta corrente opcional e certificado PFX/P12 ou CRT/PEM + KEY. Segredos e certificado são criptografados; arquivos temporários de mTLS são removidos após a chamada.

**Fora do escopo:** API Banking, saldo, extrato e pagamentos.

**PENDENTE DE CONFIRMAÇÃO:** permissões reais, emissão e liquidação de Cob/CobV e boleto, disponibilidade do PDF, callbacks, consulta e cancelamento em sandbox/homologação.

### Sicoob
- [x] documentação oficial vigente revisada
- [x] cadastro/credenciais específicas por produto
- [x] OAuth `client_credentials` de produção implementado com scopes configuráveis por produto
- [x] Access Token próprio do sandbox suportado conforme orientação oficial
- [x] certificado A1 e mTLS usando CertificateManager
- [ ] sandbox
- [x] PIX Recebimentos Cob/CobV implementado localmente
- [x] Cobrança Bancária v3 implementada localmente
- [x] boleto e boleto híbrido opcional por contrato
- [x] webhook integrado à infraestrutura comum
- [x] consulta e conciliação implementadas localmente
- [x] baixa/cancelamento implementado localmente
- [x] segunda via/PDF, linha digitável, nosso número e PIX copia e cola preservados
- [x] testes locais de contrato, normalização e regressão
- [ ] autenticação validada com credencial real
- [ ] PIX, boleto, webhook, consulta e baixa validados no sandbox
- [ ] homologação
- [ ] produção

**Estado:** código local concluído, provider ainda **não homologado/concluído operacionalmente**. O Portal fornece um Access Token específico para sandbox; produção usa OAuth `client_credentials` e mTLS. Scopes de PIX e Cobrança não são presumidos pelo código: devem ser copiados exatamente do aplicativo autorizado no Portal.

**Cobrança híbrida:** somente envia `codigoCadastrarPIX=1` quando `boleto_hibrido=1` na conta. A contratação/liberação do produto deve ser confirmada com o Sicoob.

**PENDENTE DE CONFIRMAÇÃO:** formato real do callback de Cobrança e autenticação do servidor receptor devem ser validados na homologação. Até lá o endpoint comum exige que o terminador TLS informe `SSL_CLIENT_VERIFY=SUCCESS`.

**Produtos, versões e endpoints usados:** PIX Recebimentos em `https://api.sicoob.com.br/pix/api/v2`; Cobrança Bancária vigente em `https://api.sicoob.com.br/cobranca-bancaria/v3`; OAuth em `https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token`. Bases de sandbox: `https://sandbox.sicoob.com.br/sicoob/sandbox/pix/api/v2` e `https://sandbox.sicoob.com.br/sicoob/sandbox/cobranca-bancaria/v3`.

**Configuração:** credenciais gerais ou separadas por produto, token de sandbox, scopes PIX/Cobrança, chave PIX, número do cliente, modalidade, conta, contrato, espécie do documento, indicadores de emissão/distribuição e autorização explícita de boleto híbrido. Tudo permanece em credenciais/provider_config, sem colunas bancárias globais.

**Fora do escopo:** Conta Corrente, PIX Pagamentos, Cobrança Bancária Pagamentos, TED/SPB, investimentos e qualquer saída de dinheiro.

**PENDENTE DE CONFIRMAÇÃO:** scopes concedidos, obrigatoriedade de nosso número na modalidade contratada, formato real dos callbacks, autenticação do receptor, emissão híbrida, liquidação, segunda via e baixa em sandbox/homologação.

### Sicredi
- [x] documentação oficial PIX 1.9.5 e Cobrança 3.9.1 revisada
- [x] cadastro e cofre de credenciais separados por produto
- [x] PIX OAuth2 Client Credentials com Basic + mTLS
- [x] Cobrança OAuth2 Password com Código de Acesso, `x-api-key` e `context: COBRANCA`, sem mTLS
- [x] certificado compartilhado usado exclusivamente pelo PIX
- [x] sandbox de Cobrança modelado apenas nas operações documentadas
- [x] PIX Cob/CobV, consulta, recebimentos e webhook
- [x] boleto normal e híbrido condicionado ao contrato
- [x] consulta v1 no sandbox e consulta v2 somente em produção
- [x] baixa de boleto e cancelamento de Cob/CobV
- [x] webhook de Cobrança protegido por token e com reconsulta antes da baixa financeira
- [x] testes locais de contrato, normalização e regressão
- [ ] devolução PIX (não exposta pelo contrato comum atual)
- [ ] alterações de vencimento/desconto/juros/multa (documentadas pelo banco, sem operação correspondente no contrato comum atual)
- [ ] webhook de Cobrança no sandbox (indisponível oficialmente)
- [ ] consulta v2 no sandbox (indisponível oficialmente)
- [ ] autenticação e operações validadas com credenciais reais
- [ ] homologação
- [ ] produção

**Estado:** código local concluído; provider ainda **não homologado/concluído operacionalmente**.

**Autenticações independentes:** PIX usa `https://api-pix.sicredi.com.br/oauth/token`, `client_credentials`, Basic, scopes configuráveis e mTLS. Cobrança usa `/auth/openapi/token` na base `https://api-parceiro.sicredi.com.br` (com `/sb` no sandbox), fluxo `password`, username formado por código do beneficiário + cooperativa, Código de Acesso como password, scope `cobranca`, `x-api-key` e `context: COBRANCA`; não usa mTLS.

**Produtos e endpoints:** PIX em `https://api-pix.sicredi.com.br/api/v2`, seguindo Cob/CobV e recebimentos do padrão Pix. Cobrança em `/cobranca/boleto/v1/boletos`; consulta v2 em produção em `/cobranca/boleto/v2/boletos`; baixa em `/cobranca/boleto/v1/boletos/{nossoNumero}/baixa`.

**Configuração:** PIX Client ID/Secret, x-api-key da Cobrança, Código de Acesso, token do callback, chave PIX, scopes, cooperativa, posto, código do beneficiário, espécie do documento e habilitação contratual do híbrido. Nenhuma coluna global específica foi criada.

**Webhook e conciliação:** PIX aceita a validação mTLS do terminador TLS. Cobrança usa o header/token configurado na contratação; como a API não define assinatura criptográfica padrão, o evento é tratado apenas como gatilho e a consulta remota é refeita antes de marcar pagamento.

**Limitações oficiais do sandbox:** webhook de Cobrança e consulta v2 existem apenas em produção e não são anunciados como recursos do sandbox.

**PENDENTE DE CONFIRMAÇÃO:** URLs/credenciais de homologação PIX fornecidas ao associado, payloads reais dos callbacks, campos exatos retornados na modalidade contratada, boleto híbrido e execução dos comandos assíncronos. Como a documentação pública não confirma uma URL universal de homologação PIX, o sistema exige `pix_sandbox_base_url` e `pix_sandbox_token_url` oficiais na conta e jamais redireciona o ambiente sandbox para produção.

### Banco do Brasil
- [ ] documentação revisada
- [ ] cadastro/credenciais
- [ ] autenticação
- [ ] certificado
- [ ] sandbox
- [ ] PIX
- [ ] boleto
- [ ] webhook
- [ ] consulta
- [ ] cancelamento
- [ ] testes
- [ ] homologação
- [ ] produção

### Santander
- [x] documentação oficial de Cobrança v2.6 e PIX Recebimentos revisada
- [x] credenciais criptografadas e configurações específicas por provider
- [x] OAuth2 `client_credentials` e mTLS reutilizando infraestrutura comum
- [x] Workspace obrigatória e isolada em `provider_config`
- [x] convênio e código do beneficiário isolados em `provider_config`
- [x] bases oficiais de sandbox e produção
- [x] PIX QR Code Cob/CobV, consulta e recebimentos
- [x] boleto e Boleto SX/BolePix quando contratado
- [x] consulta de boleto por beneficiário + Nosso Número
- [x] instrução `BAIXAR` e cancelamento de Cob/CobV
- [x] webhook da Workspace para boleto/PIX
- [x] reconsulta antes da baixa financeira disparada por webhook
- [x] origem de pagamento `PIX` ou `BOLETO` preservada
- [x] eventos `PAGAMENTO` e `ESTORNO` mapeados
- [x] testes locais de contrato, normalização e regressão
- [ ] autenticação e Workspace validadas com credenciais reais
- [ ] emissão, consulta, instruções e webhook validados no sandbox
- [ ] homologação
- [ ] produção

**Estado:** código local concluído; provider ainda **não homologado/concluído operacionalmente**.

**Autenticação e ambientes:** OAuth2 `client_credentials` com certificado mTLS. Cobrança usa `https://trust-sandbox.api.santander.com.br` e `https://trust-open.api.santander.com.br`, token em `/auth/oauth/v2/token` e header `X-Application-Key`. PIX usa `https://trust-pix-h.santander.com.br` e `https://trust-pix.santander.com.br`, token em `/oauth/token`.

**Workspace:** `workspace_id` é obrigatório somente no provider Santander. O registro e as instruções usam `/collection_bill_management/v2/workspaces/{workspace_id}/bank_slips`. A Workspace deve conter o convênio contratado e é onde o Santander configura `webhookURL`, avisos de boleto e avisos PIX.

**Boleto SX/BolePix:** a chave DICT é enviada apenas quando `bolepix_enabled=1`. São preservados Nosso Número, linha digitável, QR Code PIX, TXID, referência e status remoto. A geração de PDF é uma chamada separada e o link expira; sua validação real permanece pendente.

**Webhook:** eventos oficiais `PAGAMENTO` e `ESTORNO` são mapeados. `paymentType=PIX` preserva origem PIX; `SANTANDER`/`OUTROS BANCOS` preservam origem boleto/código de barras. Como o guia não define assinatura criptográfica forte para o callback de Cobrança, a notificação é gatilho auditado e o sistema reconsulta o título antes da baixa financeira.

**Fora do escopo:** pagamentos, DDA, transferência PIX, contas/tributos e Open Finance.

**PENDENTE DE CONFIRMAÇÃO:** payloads reais de cada modalidade/convênio, retorno da instrução assíncrona, geração de PDF, validação de callback em sandbox e cadeia de certificados vigente fornecida pelo Santander.

### Itaú
- [ ] documentação revisada
- [ ] cadastro/credenciais
- [ ] autenticação
- [ ] certificado
- [ ] sandbox
- [ ] PIX
- [ ] boleto
- [ ] webhook
- [ ] consulta
- [ ] cancelamento
- [ ] testes
- [ ] homologação
- [ ] produção

### Caixa
- [ ] documentação revisada
- [ ] cadastro/credenciais
- [ ] autenticação
- [ ] certificado
- [ ] sandbox
- [ ] PIX
- [ ] boleto
- [ ] webhook
- [ ] consulta
- [ ] cancelamento
- [ ] testes
- [ ] homologação
- [ ] produção

### C6 Bank
- [ ] documentação revisada
- [ ] cadastro/credenciais
- [ ] autenticação
- [ ] certificado
- [ ] sandbox
- [ ] PIX
- [ ] boleto
- [ ] webhook
- [ ] consulta
- [ ] cancelamento
- [ ] testes
- [ ] homologação
- [ ] produção

### Bradesco
- [ ] documentação revisada
- [ ] cadastro/credenciais
- [ ] autenticação
- [ ] certificado
- [ ] sandbox
- [ ] PIX
- [ ] boleto
- [ ] webhook
- [ ] consulta
- [ ] cancelamento
- [ ] testes
- [ ] homologação
- [ ] produção

### Asaas

- [x] provider habilitado
- [x] credencial API Key criptografada
- [x] ambientes sandbox e produção
- [x] PIX imediato
- [x] boleto
- [x] criação/consulta/cancelamento
- [x] QR Code e PIX copia e cola
- [x] linha digitável e URL/PDF do boleto
- [x] webhook com token e processamento pela fila comum
- [x] normalização e preservação do status remoto
- [ ] teste remoto não destrutivo da conta
- [ ] suíte específica de contrato do provider
- [ ] validação real no sandbox
- [ ] homologação e produção

**Autenticação e endpoints:** API Key no header `access_token`; produção em `https://api.asaas.com` e sandbox em `https://api-sandbox.asaas.com`; cobranças em `/v3/payments`, QR PIX em `/v3/payments/{id}/pixQrCode` e cadastro/reutilização do pagador em `/v3/customers`.

**Dados preservados:** ID remoto, referência externa, URL da fatura, linha digitável, boleto/PDF, QR Code, copia e cola, vencimento e status.

**PENDENTE DE CONFIRMAÇÃO:** executar fluxo completo com conta sandbox do titular, validar todos os eventos de webhook utilizados e criar teste de conexão que não gere cobrança.

### Mercado Pago

- [x] provider habilitado
- [x] Access Token criptografado
- [x] PIX imediato
- [x] criação/consulta/cancelamento
- [x] QR Code e PIX copia e cola
- [x] webhook validado por `x-signature`
- [x] consulta do pagamento após callback
- [x] processamento pela fila comum
- [ ] boleto neste conector
- [ ] teste remoto não destrutivo da conta
- [ ] suíte específica de contrato do provider
- [ ] validação real com credencial de teste
- [ ] homologação e produção

**Autenticação e endpoints:** Bearer Access Token; criação e consulta pela API `https://api.mercadopago.com/v1/payments`. O webhook cruza `x-signature`, `x-request-id`, timestamp e ID do pagamento antes da consulta remota.

**Dados preservados:** ID remoto, referência externa, status, valor, data do pagamento, QR Code e PIX copia e cola.

**Limitação deliberada:** o conector operacional aceita somente PIX. Boleto não deve ser anunciado como suportado até existir implementação e teste próprios.

**PENDENTE DE CONFIRMAÇÃO:** validar criação, expiração, cancelamento, assinatura e callbacks reais com credencial de teste; criar teste de conexão não destrutivo.

## Procedimento obrigatório para os próximos bancos

1. Consultar somente a documentação oficial vigente e registrar links, versão e data da revisão.
2. Registrar produtos dentro e fora do escopo antes de programar.
3. Documentar autenticação por produto sem presumir que APIs do mesmo banco compartilham credenciais, scopes ou certificados.
4. Manter dados específicos em credenciais/provider_config e reutilizar a infraestrutura comum.
5. Registrar endpoints, campos remotos preservados, webhook, conciliação, idempotência e cancelamento.
6. Executar lint, testes do provider, regressão da infraestrutura, fila e build.
7. Separar claramente: código local, sandbox real, homologação e produção.
8. Marcar como `PENDENTE DE CONFIRMAÇÃO` qualquer dado não confirmado oficialmente ou que dependa do contrato do cliente.
9. Atualizar este arquivo na mesma entrega do provider, antes do commit final.

## Pendências deliberadas

- Validar Inter e Sicoob no sandbox e na homologação do titular antes de marcá-los como concluídos operacionalmente.
- Definir o teste remoto não destrutivo de Asaas e Mercado Pago antes de habilitá-lo como teste efetivo; hoje o contrato retorna “não suportado” sem criar cobrança.
- Avaliar uma expansão separada dos estados locais para estorno, devolução, chargeback, rejeição e pagamento parcial. Os valores originais já são preservados.
- Homologação real depende de credenciais e certificados fornecidos pelo titular de cada conta.
