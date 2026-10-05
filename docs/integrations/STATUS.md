# Registro mestre das integrações bancárias

> Mensageria: a aplicação agora distingue Evolution GO da Evolution API tradicional. Consulte `docs/integrations/EVOLUTION-WHATSAPP.md`. Esta mudança não altera o estado de homologação dos providers bancários.

Atualizado em 29/09/2026. Providers Banco Inter, Sicoob, Sicredi, Santander, Itaú, Bradesco PIX e CAIXA SIGCB implementados localmente; Banco do Brasil e C6 possuem estruturas seguras bloqueadas por documentação autenticada. Homologações remotas permanecem pendentes de credenciais, certificados e contratos reais.

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
| Itaú | Sim | Sim | Pendente de credencial | Pendente | Pendente |
| Banco do Brasil | Estrutura segura, operações bloqueadas | Sim | Pendente de documentação autenticada | Pendente | Pendente |
| Bradesco | PIX local; CobV/boleto bloqueados | Sim | Pendente de credencial/certificado | Pendente | Pendente |
| CAIXA | SIGCB boleto/híbrido local; PIX avulso bloqueado | Sim | Pendente de convênio | Pendente | Pendente |
| C6 Bank | Estrutura segura, operações bloqueadas | Sim | Pendente de cadastro/documentação | Pendente | Pendente |

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
- [x] Webhook comum, validado conforme o provider, deduplicado, auditado e assíncrono
- [x] Preservação de status/evento/origem/referências remotas
- [x] Compatibilidade com Asaas e Mercado Pago
- [x] Migration retrocompatível
- [x] Testes compartilhados de infraestrutura
- [x] Deduplicação lógica de jobs financeiros ativos por tenant/provider/alvo
- [x] Renovação OAuth serializada por conta e estratégia
- [x] Testes locais HTTP mockados, webhook e isolamento multiempresa

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
- [x] documentação pública geral, credenciais e webhook revisada
- [x] campos `developer_application_key`, `client_id` e `client_secret` preparados no cofre comum
- [x] campos opcionais para Registration Access Token, convênio, chave PIX e versão da especificação
- [x] provider e conector registrados
- [x] provider mantido desabilitado para não anunciar operações não confirmadas
- [x] webhook de produção exige confirmação mTLS do terminador TLS
- [x] simulação manual de webhook no sandbox não exige mTLS, conforme documentação oficial
- [x] deduplicação, auditoria e fila comuns disponíveis na rota de webhook
- [x] payload não gera baixa financeira enquanto o contrato específico não estiver confirmado
- [x] teste garante ausência de endpoints especulativos
- [ ] OpenAPI específica de Cobrança/Boleto acessível
- [ ] fluxo OAuth específico da API de Cobrança confirmado
- [ ] endpoints e payloads de emissão, consulta e baixa confirmados
- [ ] OpenAPI específica de PIX Recebimentos acessível e contratada
- [ ] payloads e identificadores de webhook confirmados
- [ ] sandbox operacional
- [ ] homologação
- [ ] produção

**Estado:** `PENDENTE DE DOCUMENTAÇÃO BB`. A estrutura local segura está pronta, mas o provider permanece desabilitado e não pode emitir ou baixar cobranças.

**Motivo do bloqueio:** a documentação pública confirmou o modelo geral de credenciais (`developer_application_key`, `client_id`, `client_secret`) e as regras de webhook, mas a especificação OpenAPI da API específica de Cobrança/PIX não ficou acessível sem a área autenticada do Portal Developers BB. O fluxo `authorization_code` documentado publicamente pertence a APIs que exigem consentimento do cliente final e não foi reutilizado na Cobrança.

**Webhook confirmado:** em produção, o BB exige mTLS no recebimento e o certificado público do banco deve ser instalado no proxy/servidor, que repassa `SSL_CLIENT_VERIFY=SUCCESS`. No sandbox, o disparo é manual pelo Portal e não exige mTLS. O BB espera HTTP 200 ou 201 e pode repetir o evento até três vezes; a deduplicação comum absorve repetições.

**Decisão de segurança:** nenhum endpoint, scope, grant OAuth, payload de cobrança ou formato de conciliação foi criado por analogia com outra API BB. O parser financeiro retorna zero eventos até a especificação contratada ser disponibilizada.

**Para desbloquear:** exportar ou fornecer a OpenAPI vigente da aplicação BB contratada para Cobrança e, separadamente, PIX Recebimentos, contendo servidores de teste/produção, OAuth/scopes, endpoints, schemas e eventos de webhook.

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
- [x] documentação oficial atual de autenticação e certificado dinâmico revisada
- [x] credenciais criptografadas e configuração específica do provider
- [x] sandbox com autenticação simplificada isolada da produção
- [x] produção com OAuth2 `client_credentials` + mTLS
- [x] OAuthTokenManager respeita o `expires_in` devolvido, sem assumir uma hora
- [x] CRT/KEY e PFX/P12 suportados pelo CertificateManager
- [x] validade do certificado persistida como `valid_until` e exibida no teste de conexão
- [x] certificado expirado bloqueado antes da chamada produtiva
- [x] substituição/renovação de certificado reaproveita armazenamento atômico existente
- [x] PIX Cob/CobV, consulta, QR Code, webhook e conciliação
- [x] boleto e Bolecode condicionados à contratação
- [x] consulta e cancelamento/baixa
- [x] webhook como gatilho com reconsulta remota
- [x] testes locais de contrato, normalização e regressão
- [ ] autenticação simplificada validada no sandbox real
- [ ] OAuth/mTLS e certificado dinâmico validados em produção/homologação
- [ ] endpoint produtivo exato de Cobrança/Bolecode confirmado para as credenciais do titular
- [ ] payloads de webhook reais validados
- [ ] homologação
- [ ] produção

**Estado:** código local concluído; provider ainda **não homologado/concluído operacionalmente**.

**Separação de ambientes:** sandbox usa somente o token/API key de teste emitido pelo portal e nunca exige ou simula o mTLS produtivo. Produção usa `https://sts.itau.com.br/api/oauth/token`, OAuth2 `client_credentials`, CRT/KEY e mTLS tanto no token quanto no recurso protegido.

**Tokens:** o OAuthTokenManager utiliza exatamente `expires_in`, mantém cache criptografado e antecipa a renovação em 30 segundos. A documentação registra tokens produtivos de aproximadamente 300 segundos; o sistema não fixa esse valor.

**Certificado dinâmico:** o CertificateManager aceita CRT/PEM + KEY e PFX/P12, extrai fingerprint, `valid_from` e `valid_until`, armazena tudo criptografado e permite substituição sem nova coluna. O provider impede uso de certificado vencido. A emissão/renovação do CSR continua sendo uma operação de onboarding com o Itaú e não foi duplicada dentro do conector.

**Produtos e configuração:** PIX usa Cob/CobV e recebimentos. Cobrança aceita boleto e Bolecode quando `bolecode_enabled=1`. Agência, conta, dígito, carteira, beneficiário, chave PIX e URL produtiva oficial específica do contrato ficam em `provider_config`.

**URL de Cobrança:** como o próprio portal informa que a URL produtiva varia por API/produto, `production_billing_base_url` deve receber o endereço oficial associado às credenciais do cliente. O código não inventa uma URL produtiva universal.

**Fora do escopo:** pagamentos, PIX de saída, transferências, DDA, tributos e qualquer movimentação de débito.

**PENDENTE DE CONFIRMAÇÃO:** contrato/payload vigente de Cobrança e Bolecode liberado para o titular, URL produtiva exata, autenticação do webhook escolhida no onboarding, exemplos reais de liquidação/estorno e homologação da renovação do certificado.

### Caixa
- [x] manual oficial Web Service XML Cobrança Bancária e Cobrança Híbrida revisado em 29/09/2026
- [x] produto SIGCB e transporte SOAP/XML selecionados sem forçar REST
- [x] adapter XML isolado do domínio e do CobxPaymentConnector
- [x] autenticação por SHA-256/Base64 conforme leiaute oficial
- [x] inclusão de boleto convencional SIGCB 3.0
- [x] inclusão de boleto híbrido SIGCB 3.2 quando contratado
- [x] consulta de boleto SIGCB 5.2
- [x] baixa/cancelamento de boleto
- [x] Nosso Número, código de barras, linha digitável e URL preservados
- [x] QR Code/PIX copia e cola e URL do QR preservados no boleto híbrido
- [x] reconciliação por consulta e status remoto preservado
- [x] fila, auditoria e idempotência comuns reutilizadas
- [x] testes locais do hash, adapter, normalização e regressão
- [ ] API PIX Cob/CobV avulsa: contrato oficial do produto não localizado publicamente
- [ ] webhook: não consta no contrato SIGCB revisado
- [ ] origem PIX versus código de barras na liquidação do híbrido: não informada pela consulta documentada
- [ ] sandbox/homologação com convênio real
- [ ] homologação
- [ ] produção

**Estado:** cobrança SIGCB convencional e híbrida concluída no código local; provider ainda **não homologado/concluído operacionalmente**. PIX avulso permanece bloqueado até documentação oficial do produto contratado.

**Fonte oficial revisada:** [Web Service XML - Cobrança Bancária e Cobrança Híbrida PIX](https://www.caixa.gov.br/Downloads/cobranca-caixa/WEBSERVICE-XML-COBRANCA-BANCARIA.pdf), leiaute CAIXA `38.239 v010 micro`. A implementação segue o Web Service documentado e não converte o contrato em uma API REST fictícia.

**Arquitetura:** `CobxCaixaConnector` mantém o contrato comum da aplicação. Todo SOAP/XML, namespaces, envelope, hash e parsing ficam isolados em `CobxCaixaSigcbXmlAdapter`. O transporte continua usando `BankHttpClient`, que passou a aceitar corpo bruto HTTPS sem criar outro cliente HTTP.

**Endpoints SIGCB:** consulta em `https://barramento.caixa.gov.br/sibar/ConsultaCobrancaBancaria/Boleto`; inclusão, alteração e baixa em `https://barramento.caixa.gov.br/sibar/ManutencaoCobrancaBancaria/Boleto/Externo`. As chamadas usam HTTPS, POST, `USUARIO_SERVICO=SGCBS02P` e `SISTEMA_ORIGEM=SIGCB`.

**Autenticação:** não é OAuth. O campo `AUTENTICACAO` contém SHA-256 em Base64 de código do beneficiário (7), Nosso Número (17), vencimento DDMMAAAA (8), valor sem separador (15) e CPF/CNPJ do beneficiário (14). Consulta e baixa zeram vencimento e valor, conforme o manual.

**Boleto convencional:** versão 3.0, operação `INCLUI_BOLETO`. Preserva Nosso Número, código de barras, linha digitável e URL/segunda via. Consulta usa versão 5.2 e `CONSULTA_BOLETO`; cancelamento usa `BAIXA_BOLETO`.

**Boleto híbrido:** versão 3.2 e `TIPO=HIBRIDO`, condicionado a `hybrid_boleto=1`. Preserva `QRCODE` como PIX copia e cola e `URL_QRCODE`, além dos dados tradicionais do boleto. Como a resposta documentada não traz TXID separado, o sistema não fabrica esse identificador.

**Conciliação e webhook:** o contrato revisado oferece consulta de situação, mas não documenta callback/webhook. A conciliação implementada é por consulta enfileirável. Em boleto híbrido, a consulta documentada não identifica se a liquidação ocorreu por PIX ou código de barras; a origem fica `PIX_OR_BOLETO_NAO_INFORMADO`, sem inferência financeira incorreta.

**CNAB:** não foi implementado. O Web Service cobre criação, consulta e baixa online; o manual revisado não torna CNAB obrigatório para essas operações. Se o convênio contratado exigir arquivo retorno para conciliação em lote, isso será uma fase separada e documentada.

**PENDENTE DE CONFIRMAÇÃO:** ativação do convênio SIGCB, autorização para híbrido, dados reais do beneficiário, comportamento do Nosso Número, disponibilidade/ambiente de homologação, regras específicas da carteira, identificação da origem da liquidação híbrida e contrato oficial da API PIX Cob/CobV avulsa.

**Fora do escopo:** Pix Automático, pagamentos, PIX de saída, saldo, extrato, Open Finance e CNAB não exigido pelo contrato.

### C6 Bank
- [x] portal C6 Developers e páginas institucionais oficiais revisados em 29/09/2026
- [x] produtos públicos de PIX, boleto e boleto com QR Code confirmados
- [x] processo público de cadastro, credenciais de teste, sandbox, evidências e liberação produtiva confirmado
- [x] provider registrado no contrato comum em modo seguro e desabilitado
- [x] operações bloqueadas sem inventar endpoint, OAuth, scope, certificado, payload ou webhook
- [x] teste local garante ausência de endpoints e autenticação especulativos
- [ ] cadastro da empresa no C6 Developers
- [ ] documentação técnica autenticada de PIX recebimentos
- [ ] documentação técnica autenticada de boleto/cobrança
- [ ] endpoint/base URL de sandbox e produção
- [ ] método de autenticação e formato das credenciais
- [ ] scopes/permissões de PIX e boleto
- [ ] exigência e formato de certificado/mTLS
- [ ] endpoints e schemas de criação, consulta e baixa
- [ ] contrato, autenticação e eventos de webhook
- [ ] campos de TXID, Nosso Número, linha digitável, PDF, QR Code, copia e cola, status e origem da liquidação
- [ ] sandbox real
- [ ] PIX
- [ ] boleto
- [ ] consulta
- [ ] cancelamento/baixa
- [ ] webhook e conciliação
- [ ] homologação
- [ ] produção

**Estado:** `PENDENTE DE DOCUMENTAÇÃO C6`. A estrutura local está preparada e testada, mas o provider permanece desabilitado e não anuncia método de pagamento até a especificação técnica oficial ser obtida no portal autenticado.

**Fontes oficiais revisadas:** [C6 Bank Developers](https://developers.c6bank.com.br/), [Integração via APIs C6](https://www.c6bank.com.br/apis-integracao/) e [orientação oficial de integração e homologação](https://www.c6bank.com.br/blog/api-c6-bank). As páginas públicas confirmam produtos e jornada de homologação, mas não publicam o contrato técnico necessário para codificação.

**Produtos confirmados:** a página oficial informa boleto com emissão, gestão, vencimentos, multas, descontos e opção de PIX QR Code; PIX com criação de cobranças, consulta de transações e recebimento por QR Code ou chave. Pagamentos, Pix Automático, C6 Pay, adquirência, e-commerce, extrato, DDA e saídas financeiras estão fora desta tarefa.

**Processo confirmado:** cadastro no C6 Developers, recebimento das credenciais de teste, implementação em sandbox, envio de evidências para validação técnica, assinatura do termo de responsabilidade e liberação para produção.

**PENDENTE DE DOCUMENTAÇÃO C6 — dados exatos necessários:**

- base URLs e endpoints de sandbox e produção para PIX Cob/CobV e boleto;
- autenticação, token URL, grant, formato de credenciais e renovação;
- scopes/permissões separados por produto;
- necessidade de certificado, mTLS, formato e cadeia confiável;
- payloads e respostas de criar, consultar e cancelar/baixar;
- contrato de webhook, assinatura/mTLS, cadastro, eventos e política de reconsulta;
- campos oficiais de TXID, ID externo, Nosso Número, linha digitável, código de barras, PDF, QR Code, PIX copia e cola, status remoto e origem da liquidação;
- suporte efetivo a cancelamento, estorno, consulta de recebidos e boleto híbrido no produto contratado.

**Decisão de segurança:** nenhum campo de credencial foi criado, porque até seus nomes e separação por produto dependem da documentação autenticada. Assim que o C6 liberar os materiais, eles serão armazenados no cofre/provider_config e processados pela infraestrutura comum, sem criar OAuth, certificado, fila, webhook ou idempotência paralelos.

**Fora do escopo:** pagamentos a fornecedores, PIX de saída, adquirência, e-commerce, Checkout C6 Pay, Pix Automático, extrato e DDA.

### Bradesco
- [x] portal oficial e Manual API Pix 2.0.0 público revisados em 29/09/2026
- [x] cadastro de credenciais PIX no cofre comum
- [x] OAuth2 Client Credentials com HTTP Basic pelo OAuthTokenManager
- [x] mTLS no token e nos recursos pelo CertificateManager
- [x] URL oficial de homologação PIX
- [x] PIX imediato (Cob) com TXID determinístico
- [x] consulta e cancelamento de Cob
- [x] Pix recebidos e conciliação por webhook com reconsulta
- [x] status remoto, TXID, EndToEndId e origem PIX preservados
- [x] fila, auditoria e idempotência comuns reutilizadas
- [x] testes locais de contrato, normalização e regressão
- [ ] PIX com vencimento (CobV): não consta na especificação pública revisada
- [ ] boleto e boleto com PIX: especificação técnica oficial não disponível publicamente
- [ ] PIX copia e cola: confirmar endpoint/campo vigente no onboarding
- [ ] autenticação validada com credenciais reais
- [ ] sandbox real validado
- [ ] homologação
- [ ] produção

**Estado:** Pix imediato concluído no código local; provider ainda **não homologado/concluído operacionalmente**. CobV, boleto e boleto com PIX permanecem bloqueados até documentação oficial específica.

**Fontes oficiais revisadas:** portal [APIs do Bradesco](https://api.bradesco/) e [Manual API Pix 2.0.0](https://empresas.bradesco/pix/assets/docs/api_pix_200.pdf). O manual público encontrado identifica “Versão 03 — Outubro/2020”; apesar de ainda estar publicado no domínio oficial, o conteúdo dependente de produto/contrato precisa ser reconfirmado no onboarding.

**Autenticação PIX:** OAuth2 `client_credentials`, autenticação do cliente por HTTP Basic e mTLS. Homologação usa `https://qrpix-h.bradesco.com.br/auth/server/oauth/token` e recursos em `https://qrpix-h.bradesco.com.br`. Produção usa recursos em `https://qrpix.bradesco.com.br`; a URL exata do token produtivo deve ser a fornecida no onboarding e fica em `provider_config.production_token_url`, sem inferência.

**PIX implementado:** `PUT /cob/{txid}`, `GET /cob/{txid}` e `PATCH /cob/{txid}` com status `REMOVIDA_PELO_USUARIO_RECEBEDOR`. O callback oficial entrega `pix[]`; cada evento é tratado como gatilho e a Cob é reconsultada antes da baixa financeira. São preservados TXID, EndToEndId, valor, horário, status remoto e origem `PIX`.

**Webhooks e recebimentos:** a documentação pública confirma `PUT /webhook`, `GET /webhook`, `DELETE /webhook`, callback em `{webhookUrl}/pix`, `GET /pix/{e2eid}` e `GET /pix`. O conector recebe o callback pela infraestrutura comum e reconcilia pelo TXID. O cadastro remoto do webhook e a consulta periódica por intervalo ficam pendentes de credenciais e validação no sandbox.

**CobV:** o próprio Manual API Pix 2.0.0 informa que a versão publicada contempla pagamentos imediatos e que cobrança com vencimento seria especificada em versão posterior. Por isso o sistema recusa uma cobrança PIX futura em vez de enviá-la incorretamente como Cob.

**Boleto e boleto com PIX:** o portal oficial confirma comercialmente os produtos “Cobrança” e “Cobrança com QR Code”, mas não foi localizada uma especificação técnica pública oficial com autenticação, base URL, endpoints, versão e payloads. Nenhum endpoint foi inventado e o método `boleto` não é anunciado pelo conector.

**PENDENTE DE CONFIRMAÇÃO:** documentação técnica vigente de CobV e Cobrança, URL produtiva do token, scopes liberados, geração do PIX copia e cola, autenticação/verificação do callback, cadastro do webhook, payloads reais, sandbox, homologação e produção.

**Fora do escopo:** saldo, extrato, pagamentos, PIX de saída, transferências e Open Finance.

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
- `PENDENTE DE HOMOLOGAÇÃO DE IDEMPOTÊNCIA`: Inter boleto, Sicoob boleto, Sicredi boleto, Santander boleto, Itaú boleto/Bolecode, CAIXA SIGCB e Asaas não possuem retentativa automática de criação até que consulta pós-timeout ou idempotência oficial seja comprovada no produto contratado.
- PIX por TXID de Inter, Sicoob, Sicredi, Santander, Itaú e Bradesco está classificado localmente como consulta/recurso determinístico, mas a retentativa automática permanece desativada até homologação. Mercado Pago já envia `X-Idempotency-Key`, porém a fila também permanece conservadora antes do teste real.

## Auditoria técnica final — 29/09/2026

A implementação foi confrontada com o código, migrations e testes em `docs/integrations/AUDITORIA-FINAL.md`. A auditoria corrigiu conciliação ambígua, preservação de `provider_event`, armazenamento criptografado do payload bruto de webhook, autenticação permissiva de callbacks, recuperação de jobs abandonados, retry automático de `POST` e uma migration redundante. Nenhum sandbox, homologação ou ambiente produtivo foi promovido de estado.
