# CobrançaFácil — visão do sistema e especificação para integrações bancárias

**Versão deste documento:** 1.0
**Finalidade:** apresentar o CobrançaFácil a bancos, instituições de pagamento, gateways e equipes técnicas, e orientar a solicitação da documentação necessária para implementar novos conectores.

## 1. O que é o CobrançaFácil

O CobrançaFácil é uma plataforma web multiempresa para gestão e automação de cobranças. Cada empresa usuária mantém seus próprios clientes, produtos, cobranças, parcelas, contas de recebimento e canais de comunicação. O sistema foi projetado para operar como SaaS e separar os dados e as credenciais de cada empresa.

O sistema não movimenta dinheiro por conta própria e não substitui o banco. Ele atua como integrador: envia instruções para a API da instituição financeira, armazena os identificadores e meios de pagamento retornados e recebe notificações para conciliar o pagamento no sistema.

### 1.1 Público-alvo

- prestadores de serviços e pequenas e médias empresas;
- escritórios, clínicas, escolas, academias, imobiliárias e empresas de assinatura;
- empresas com matriz, filiais ou várias contas de recebimento;
- operações que precisam cobrar por PIX e boleto, acompanhar vencimentos e automatizar lembretes;
- empresas que desejam centralizar provedores bancários diferentes sem mudar o fluxo interno de cobrança.

### 1.2 Funções disponíveis

- cadastro de empresas, usuários, clientes, produtos e planos;
- importação de clientes e cobranças por CSV/XLSX;
- criação de cobranças à vista ou parceladas;
- seleção do método `pix` ou `boleto`;
- seleção da conta de recebimento em cada cobrança;
- definição de uma conta padrão ativa por empresa;
- geração assíncrona dos meios de pagamento;
- armazenamento de QR Code PIX, PIX copia e cola, URL de pagamento, linha digitável e PDF/URL do boleto;
- consulta e cancelamento remoto por conector;
- baixa automática por webhook;
- consulta periódica como mecanismo complementar de conciliação;
- auditoria das alterações e eventos recebidos;
- fila persistente com tentativas e atraso exponencial;
- régua de cobrança por email e WhatsApp;
- portal público seguro para o pagador consultar a cobrança e o comprovante.

## 2. Arquitetura da integração

O frontend React chama uma API PHP. A API grava a cobrança e suas parcelas no MariaDB e cria um trabalho na fila `integration_jobs`. Um trabalhador processa o trabalho, seleciona o conector da conta escolhida e chama a API externa.

Fluxo resumido:

1. A empresa cadastra uma conta de recebimento.
2. O sistema criptografa a credencial e o segredo do webhook antes de gravá-los.
3. O usuário cria uma cobrança e escolhe conta e método.
4. A cobrança e as parcelas são gravadas localmente.
5. Um trabalho `generate_charge` é colocado na fila persistente.
6. O trabalhador chama `CobxPaymentConnector::create()`.
7. O conector cria ou localiza o pagador remoto, quando necessário.
8. O conector cria a cobrança remota usando uma referência local idempotente.
9. O retorno normalizado é gravado na parcela.
10. O banco envia um webhook quando o estado muda.
11. A assinatura do webhook é validada antes do processamento.
12. O evento é deduplicado, auditado e usado para baixar a parcela.
13. O estado consolidado da cobrança é recalculado.

### 2.1 Contrato interno obrigatório do conector

Todo provedor implementa `CobxPaymentConnector`, localizado em `api/lib/payment_connector.php`:

```php
interface CobxPaymentConnector
{
    public function provider(): string;
    public function paymentMethods(): array;
    public function create(PDO $pdo, array $account, array $charge, array $installment, string $method): array;
    public function fetch(array $account, string $externalId): array;
    public function cancel(array $account, string $externalId): array;
    public function normalize(array $remote): array;
    public function verifyWebhook(PDO $pdo, string $companyId, string $raw, array $server, array $query): bool;
    public function webhookEvents(PDO $pdo, string $companyId, string $raw, array $query): array;
}
```

Responsabilidades:

| Método | Responsabilidade |
|---|---|
| `provider()` | Identificador estável e minúsculo do provedor. |
| `paymentMethods()` | Métodos realmente suportados, atualmente `pix` e/ou `boleto`. |
| `create()` | Criar a cobrança remota e devolver os dados de pagamento normalizados. |
| `fetch()` | Consultar a situação atual usando o identificador remoto. |
| `cancel()` | Cancelar ou remover a cobrança remota conforme a API. |
| `normalize()` | Converter estados, datas e comprovante do banco para o domínio local. |
| `verifyWebhook()` | Validar token, HMAC, certificado ou assinatura antes de confiar no evento. |
| `webhookEvents()` | Converter um payload de webhook em um ou mais eventos normalizados. |

### 2.2 Saída esperada ao criar uma cobrança

```json
{
  "ok": true,
  "detail": "Cobrança criada.",
  "external_id": "identificador-no-banco",
  "payment_url": "https://...",
  "boleto_digitable_line": "opcional",
  "boleto_pdf_url": "opcional",
  "pix_qrcode": "imagem-base64-ou-conteúdo-suportado",
  "pix_copy_paste": "payload-EMV-do-PIX"
}
```

`external_id` é obrigatório em uma criação bem-sucedida. Os demais campos dependem do método e da API.

### 2.3 Evento normalizado de webhook

```json
{
  "external_id": "identificador-no-banco",
  "reference": "installment:UUID-LOCAL",
  "amount": 125.90,
  "paid_at": "2026-09-29T14:30:00-03:00",
  "status": "paid"
}
```

Estados locais usados pelos conectores:

- `pending`: criado, aguardando pagamento ou em processamento;
- `paid`: recebido, confirmado ou liquidado;
- `overdue`: vencido e ainda não pago;
- `cancelled`: cancelado, removido, estornado ou sujeito a chargeback, conforme o mapeamento do provedor.

Se a instituição distinguir estorno, devolução parcial, contestação e chargeback, sua documentação deve informar todos esses eventos. Um novo conector poderá ampliar o domínio local para não perder essa informação.

## 3. Dados enviados ao banco

O conjunto exato depende da instituição, mas o sistema já dispõe de:

### 3.1 Pagador

- identificador interno do cliente;
- nome ou razão social;
- CPF ou CNPJ sem máscara;
- email;
- telefone/celular;
- endereço, quando preenchido;
- identificador remoto previamente criado no provedor.

### 3.2 Cobrança/parcela

- identificador interno da cobrança;
- identificador interno da parcela;
- número da parcela;
- descrição;
- valor com duas casas decimais;
- data de vencimento;
- método (`pix` ou `boleto`);
- referência externa no formato `installment:<UUID>`;
- URL de notificação, quando aceita pela API.

Nunca se deve usar apenas valor e data para conciliar. A prioridade é: referência local, identificador remoto e, somente como último recurso controlado, valor combinado com a parcela pendente.

## 4. Integrações existentes

### 4.1 Asaas

Métodos atuais: PIX e boleto.

- Sandbox: `https://api-sandbox.asaas.com`
- Produção: `https://api.asaas.com`
- Autenticação atual: header `access_token`.
- Criação/localização de pagador: `POST /v3/customers`.
- Criação de cobrança: `POST /v3/payments`.
- QR Code PIX: `GET /v3/payments/{id}/pixQrCode`.
- Consulta: `GET /v3/payments/{id}`.
- Cancelamento: `DELETE /v3/payments/{id}`.
- Boleto: usa `identificationField`, `bankSlipUrl` e/ou `invoiceUrl` retornados.
- Webhook: valida o header `asaas-access-token` contra o segredo criptografado da conta.
- Eventos de baixa considerados: `PAYMENT_RECEIVED` e `PAYMENT_CONFIRMED`.

Para criar o cliente remoto, CPF/CNPJ é obrigatório no conector atual. O ID remoto é gravado no cliente e reutilizado.

### 4.2 Mercado Pago

Método atual: PIX.

- Base usada: `https://api.mercadopago.com`.
- Autenticação: `Authorization: Bearer <access-token>`.
- Criação: `POST /v1/payments`.
- Consulta: `GET /v1/payments/{id}`.
- Cancelamento: `PUT /v1/payments/{id}` com `{"status":"cancelled"}`.
- Idempotência: header `X-Idempotency-Key: cobx-<UUID-DA-PARCELA>`.
- Referência: `external_reference` e `metadata.installment_id`/`charge_id`.
- Webhook: valida HMAC-SHA256 com `x-signature`, `x-request-id`, timestamp e ID do recurso.
- Após o webhook, o sistema consulta o pagamento na API antes de dar baixa.
- Estados `approved` e `authorized` são normalizados como pagos.

Email válido do pagador é obrigatório para a geração PIX atual. CPF/CNPJ é enviado quando disponível.

## 5. PIX, vencimento e “PIX agendado”

É necessário separar três conceitos:

1. **Cobrança PIX imediata:** gera QR Code dinâmico e copia e cola para pagamento.
2. **Cobrança PIX com vencimento futuro:** a cobrança possui calendário/vencimento, mas o pagador decide quando pagar.
3. **Agendamento de pagamento PIX:** uma conta pagadora autoriza hoje um débito futuro. Isso é uma operação diferente, normalmente exige API de iniciação de pagamento, consentimento, autenticação forte e regras do Open Finance.

O CobrançaFácil atualmente gera cobranças para recebimento; ele não controla a conta pagadora do cliente final. Portanto, para oferecer o item 3, deve-se obter do banco documentação específica de iniciação/agendamento de PIX e não apenas a API de cobrança PIX.

Ao falar com o banco, perguntar explicitamente:

- a API cria cobrança PIX imediata (`cob`) ou cobrança com vencimento (`cobv`)?
- suporta calendário, expiração, juros, multa, desconto e abatimento?
- “PIX agendado” é uma cobrança com vencimento ou iniciação de pagamento na conta do pagador?
- exige participação como iniciador de transação de pagamento ou consentimento Open Finance?
- existe fluxo de autorização/redirecionamento do pagador?
- como cancelar um agendamento já autorizado?
- quais eventos notificam agendamento criado, executado, rejeitado ou cancelado?

## 6. O que solicitar a um novo banco

Esta é a lista mínima para decidir se a integração é possível.

### 6.1 Acesso e habilitação

- link do portal de desenvolvedores;
- documentação OpenAPI/Swagger ou coleção Postman oficial;
- processo para criar aplicação em sandbox e produção;
- requisitos comerciais para habilitar API de cobrança;
- tipos de conta e produtos elegíveis;
- homologação obrigatória e roteiro de certificação;
- SLA e canal de suporte técnico;
- limites de chamadas por minuto/dia e política de bloqueio;
- lista de IPs/domínios do banco, se aplicável.

### 6.2 Autenticação

Solicitar resposta objetiva para:

- API Key, Basic, OAuth 2.0 Client Credentials ou outro fluxo?
- URLs de token de sandbox e produção;
- `client_id`, `client_secret`, escopos e audiência exigidos;
- tempo de validade do token e forma de renovação;
- exige mTLS?
- exige certificado ICP-Brasil ou certificado emitido pelo próprio banco?
- formato aceito: `.pfx/.p12`, `.pem`, chave privada e cadeia intermediária;
- senha do certificado, procedimento de rotação e antecedência de renovação;
- exige assinatura JWS/JWT do corpo, header adicional ou chave pública registrada?
- exige IP fixo/allowlist?

Credenciais de produção nunca devem ser enviadas por email comum nem incluídas nesta documentação. Devem ser cadastradas diretamente no ambiente seguro do sistema.

### 6.3 PIX cobrança

Solicitar os endpoints e exemplos completos para:

- criar cobrança imediata;
- criar cobrança com vencimento;
- consultar por `txid`/ID;
- revisar/alterar cobrança;
- cancelar/remover cobrança;
- obter QR Code, imagem e payload copia e cola;
- consultar PIX recebidos e devoluções;
- solicitar devolução total/parcial;
- obter comprovante ou URL/PDF equivalente;
- configurar webhook por chave PIX, conta ou aplicação;
- paginação e conciliação por período.

Perguntar também:

- regras e tamanho do `txid`;
- suporte a referência externa própria e idempotência;
- campos obrigatórios do devedor;
- necessidade de chave PIX cadastrada;
- códigos de estado e de erro;
- timezone das datas;
- precisão monetária e tratamento de centavos;
- retenção dos dados e janela máxima de consulta.

### 6.4 Boleto

Solicitar os endpoints e exemplos para:

- registrar boleto;
- consultar boleto;
- baixar/cancelar boleto;
- alterar vencimento, valor, juros, multa, desconto e instruções;
- obter nosso número, código de barras e linha digitável;
- obter PDF ou HTML imprimível;
- receber eventos de registro, liquidação, baixa, protesto e rejeição;
- consultar liquidações por período;
- tratar pagamento parcial, valor divergente e pagamento após vencimento.

Confirmar:

- carteira, convênio, agência, conta, código do beneficiário e modalidade necessários;
- se o boleto é híbrido com QR Code PIX;
- regras de homologação da carteira;
- webhook disponível ou necessidade de CNAB 240/400;
- leiaute e códigos de ocorrência caso a conciliação seja por arquivo.

### 6.5 Webhooks

O banco deve informar:

- eventos disponíveis e exemplos reais de payload;
- método e URL de cadastro do webhook;
- assinatura: HMAC, JWT/JWS, token fixo, mTLS ou certificado;
- headers usados na assinatura;
- algoritmo e string canônica exata;
- política de retentativa, intervalos e tempo limite;
- identificador único do evento;
- ordem de entrega e possibilidade de eventos duplicados ou fora de ordem;
- IPs de origem, se publicados;
- resposta HTTP esperada para confirmar recebimento;
- forma de reenviar ou consultar eventos perdidos;
- diferença entre ambiente sandbox e produção.

O endpoint do CobrançaFácil segue este padrão:

```text
POST https://DOMINIO-DO-SISTEMA/api/webhooks/payment/PROVEDOR/UUID-DA-EMPRESA
```

Em instalação por subpasta, a subpasta faz parte da URL. O endpoint precisa estar publicamente acessível por HTTPS; `127.0.0.1` e `localhost` não funcionam para webhooks externos.

### 6.6 Idempotência e erros

Solicitar:

- header ou campo oficial de idempotência;
- duração da janela de idempotência;
- comportamento ao repetir a mesma chave com payload diferente;
- formato padrão de erro;
- códigos HTTP e códigos internos;
- indicação de erro transitório ou definitivo;
- tratamento de timeout quando o banco processa a requisição mas a resposta não chega;
- endpoint recomendado para confirmar o resultado após timeout.

## 7. Questionário pronto para enviar ao banco

> Estamos integrando o banco à plataforma CobrançaFácil, um sistema multiempresa de geração e conciliação de cobranças. Precisamos da documentação técnica oficial de sandbox e produção para PIX cobrança e boleto. Favor fornecer:
>
> 1. Portal do desenvolvedor, OpenAPI/Swagger e coleção Postman oficial.
> 2. Processo de credenciamento, homologação e ativação em produção.
> 3. Autenticação completa: OAuth/API Key, escopos, token, mTLS, certificados, assinatura e IP fixo.
> 4. URLs base de sandbox e produção.
> 5. PIX: criar cobrança imediata, cobrança com vencimento, consultar, cancelar/revisar, QR Code, copia e cola, devolução, comprovante e conciliação.
> 6. Esclarecimento se há API de agendamento de pagamento PIX ou somente cobrança com vencimento.
> 7. Boleto: registrar, consultar, alterar, baixar, linha digitável, código de barras, PDF, liquidação e boleto híbrido.
> 8. Webhooks: cadastro, eventos, payloads, assinatura, deduplicação, retentativas e IPs de origem.
> 9. Referência externa e idempotência para criação/cancelamento.
> 10. Tabela completa de estados, erros, limites de API e política de retentativa.
> 11. Campos obrigatórios do pagador e do beneficiário.
> 12. Dados necessários da conta: agência, conta, convênio, carteira, código do beneficiário e chave PIX.
> 13. Possibilidade de múltiplas contas/filiais por aplicação ou necessidade de credenciais separadas.
> 14. Endpoint de consulta de pagamentos por período para reconciliação de contingência.
> 15. Contato do suporte técnico responsável pela homologação.

## 8. Adaptações necessárias ao adicionar um provedor

Além de implementar a classe do conector, o desenvolvimento deverá:

1. adicionar o identificador do provedor à fábrica `cobx_connector()`;
2. informar os métodos em `paymentMethods()`;
3. adaptar o cadastro de contas e sua validação;
4. ampliar o tipo/enum `payment_accounts.provider` no schema e nas migrações;
5. disponibilizar o provedor no formulário React;
6. criar a autenticação HTTP exigida, inclusive cache/renovação de OAuth;
7. implementar idempotência, consulta e cancelamento;
8. implementar e testar assinatura do webhook;
9. mapear todos os estados remotos;
10. persistir comprovante, linha digitável, PDF e PIX retornados;
11. criar testes com fixtures de sandbox e payloads assinados;
12. documentar configuração, certificados e URL de webhook;
13. homologar em sandbox antes de ativar produção.

Se houver OAuth ou mTLS, o modelo atual de `api_key`, `public_key` e `webhook_secret` precisará ser ampliado para armazenar com criptografia campos como `client_id`, `client_secret`, certificado, chave privada, senha do certificado, escopos e expiração do token. Certificados e chaves não devem ser armazenados no repositório Git.

## 9. Segurança e conformidade

- Credenciais bancárias e segredos de webhook são criptografados no banco usando a chave da aplicação.
- A API nunca devolve a credencial completa para o frontend; apenas informa que existe e apresenta máscara.
- Webhooks sem assinatura válida recebem HTTP 401.
- Eventos possuem chave de deduplicação e histórico de processamento.
- Operações externas passam por fila com até cinco tentativas por padrão e backoff exponencial.
- Alterações de cobranças são registradas em auditoria.
- A comunicação com instituições deve usar HTTPS e validação normal da cadeia TLS.
- Logs não devem registrar tokens, chaves privadas, documentos completos ou payloads sensíveis sem mascaramento.
- A instituição e a empresa contratante devem definir responsabilidades relacionadas a LGPD, retenção, base legal e atendimento a titulares.
- O ambiente de produção deve separar credenciais, banco, certificados e webhooks do sandbox.

## 10. Critérios de aceite de um novo conector

Um conector só deve ser considerado pronto quando:

- autentica em sandbox e produção sem credenciais no código;
- cria cobrança sem duplicar em caso de retentativa;
- devolve e persiste todos os dados necessários ao pagamento;
- consulta e normaliza todos os estados documentados;
- cancela conforme as regras do banco;
- valida criptograficamente o webhook ou usa o mecanismo oficial equivalente;
- ignora eventos duplicados com segurança;
- concilia por referência/ID, nunca apenas pelo nome do cliente;
- registra auditoria e erros sem expor segredo;
- lida corretamente com timeout, indisponibilidade e limite de chamadas;
- possui testes de criação, consulta, cancelamento, webhook válido, webhook inválido e duplicidade;
- passou pelo roteiro de homologação da instituição.

## 11. Informações que ainda dependerão do banco escolhido

Para estimar prazo e custo de cada integração, são indispensáveis:

- nome da instituição e produto contratado;
- documentação oficial e contato de homologação;
- métodos desejados: PIX imediato, PIX com vencimento, iniciação/agendamento, boleto ou boleto híbrido;
- modelo de autenticação e certificados;
- existência e qualidade dos webhooks;
- disponibilidade de sandbox;
- regras de múltiplas contas e subcontas;
- necessidade de CNAB ou conciliação complementar;
- escopo de devoluções, estornos e comprovantes.

Sem essas informações é possível preparar a arquitetura, mas não concluir nem homologar o conector. A integração efetiva exige que o titular da conta obtenha autorização e credenciais junto à instituição.
