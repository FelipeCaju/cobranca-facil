# Preparação técnica para homologação bancária

**Data:** 29/09/2026
**Versão:** 1.10.0 · Build 20260929.14

Este documento registra apenas bloqueadores técnicos tratados antes da homologação. Nenhum sandbox, homologação ou ambiente produtivo foi declarado concluído.

## Problemas corrigidos

- Jobs financeiros ativos agora possuem deduplicação lógica transacional.
- Renovação OAuth concorrente é serializada por conta, tenant e estratégia de token.
- Webhook mTLS só é aceito quando o tenant possui conta ativa daquele provider.
- Criação remota não é repetida automaticamente pela fila enquanto a idempotência real não estiver homologada.
- Cliente HTTP passou a ter testes locais para códigos, falhas, timeout e conteúdo inválido.
- Foram adicionados testes adversariais de webhook, duplicidade, referência inexistente e isolamento de tenant.
- Foi criado diagnóstico/migrador reversível de segredos legados, sem execução automática.

## Migration criada

`database/migrations/20260929_preproduction_queue.sql`

Ela adiciona `integration_jobs.dedupe_key` e o índice único `uq_jobs_active_dedupe`. A migration pode ser executada novamente: verifica coluna e índice antes de criá-los. Foi validada no banco existente e em execução repetida.

## Estratégia de deduplicação da fila

A chave é SHA-256 de:

```text
company_id | job_type | provider | tipo-do-alvo:id-do-alvo
```

O alvo é `charge_id`, `installment_id` ou `event_key`. O provider vem do payload ou é resolvido pela cobrança/parcela dentro do mesmo tenant.

- O índice único impede dois inserts concorrentes do mesmo job lógico.
- Em colisão, `cobx_queue_enqueue` devolve o ID do job ativo existente.
- A chave permanece durante `pending` e `processing`.
- Ao terminar em `completed` ou `failed`, a chave vira `NULL`; uma nova operação legítima pode ser criada.
- Recovery de jobs abandonados, claim atômico, backoff e limite de tentativas permanecem ativos.
- `payment_webhook` também conserva a deduplicação própria por evento.

## Idempotência remota por provider

Classificação conservadora baseada no contrato já documentado no projeto:

| Provider/operação | Classe | Estratégia atual | Estado |
|---|---|---|---|
| Inter PIX Cob/CobV | B | TXID determinístico e consulta por TXID existem; não há retry automático antes da homologação do fluxo pós-timeout | Pendente de homologação |
| Inter boleto | C | Criação não é repetida automaticamente | `PENDENTE DE HOMOLOGAÇÃO DE IDEMPOTÊNCIA` |
| Sicoob PIX | B | TXID determinístico/consulta; retry automático conservadoramente desligado | Pendente de homologação |
| Sicoob boleto | C | Não foi presumida chave oficial | `PENDENTE DE HOMOLOGAÇÃO DE IDEMPOTÊNCIA` |
| Sicredi PIX | B | TXID determinístico/consulta; retry desligado | Pendente de homologação |
| Sicredi boleto | C | Não foi presumido comportamento de criação repetida | `PENDENTE DE HOMOLOGAÇÃO DE IDEMPOTÊNCIA` |
| Santander PIX | B | Identificador determinístico e consulta; retry desligado | Pendente de homologação |
| Santander boleto/BolePix | C | Retry desligado | `PENDENTE DE HOMOLOGAÇÃO DE IDEMPOTÊNCIA` |
| Itaú PIX | B | TXID determinístico e consulta; retry desligado | Pendente de homologação |
| Itaú boleto/Bolecode | C | Retry desligado | `PENDENTE DE HOMOLOGAÇÃO DE IDEMPOTÊNCIA` |
| Bradesco PIX | B | TXID determinístico e consulta; retry desligado | Pendente de homologação |
| Bradesco CobV/boleto | C | Operações nem sequer são anunciadas | Bloqueado por documentação |
| CAIXA SIGCB | C | Nosso Número pode depender do modo contratado; não há preflight seguro universal implementado | `PENDENTE DE HOMOLOGAÇÃO DE IDEMPOTÊNCIA` |
| Asaas | C | `externalReference` é enviado, mas a consulta prévia pós-timeout ainda não foi implementada/validada | `PENDENTE DE HOMOLOGAÇÃO DE IDEMPOTÊNCIA` |
| Mercado Pago | A | Usa `X-Idempotency-Key` oficial e determinístico por parcela | Mecanismo local presente; validação real pendente |

Mesmo na classe A/B, a fila usa `max_attempts=1` para `generate_charge` nesta fase. Isso evita duplicação financeira até os testes reais confirmarem 409, timeout após envio e recuperação por consulta. Não foi criada uma falsa idempotência universal.

## Testes adicionados

- `queue_dedupe_smoke.php`: colisão ativa e liberação após estado terminal.
- `http_oauth_mock_smoke.php`: HTTP 200, 201, 400, 401, 403, 404, 409, 422, 429, 500 e 502; conexão recusada; timeout após envio; JSON/XML inválidos; token expirado; renovação concorrente; separação entre contas.
- `webhook_multitenant_smoke.php`: autenticação por provider, mTLS ausente, token inválido, payload inválido, evento financeiro/não financeiro, duplicidade, referência inexistente, external ID entre tenants e isolamento de conta/token.
- A suíte anterior de infraestrutura, fila, providers, sintaxe PHP, lint e build continua obrigatória.

Os testes usam servidor HTTP local e banco XAMPP. Nenhuma API bancária real é chamada.

## OAuthTokenManager

Uma trava nomeada MySQL é calculada com `company_id`, `payment_account_id` e a chave da estratégia OAuth. Depois de adquirir a trava, o manager relê o cache antes de solicitar token. Assim, dois workers concorrentes reutilizam a primeira renovação, enquanto contas diferentes nunca compartilham token.

## Segredos legados

Comando criado:

```powershell
C:\xampp\php\php.exe scripts\legacy-secrets.php --dry-run
C:\xampp\php\php.exe scripts\legacy-secrets.php --apply --backup=C:\caminho-seguro\backup-segredos.json
C:\xampp\php\php.exe scripts\legacy-secrets.php --rollback --backup=C:\caminho-seguro\backup-segredos.json
```

- O padrão é diagnóstico, sem alteração.
- `--apply` exige caminho novo de backup e não sobrescreve arquivo.
- Alterações ocorrem em transação.
- O backup permite rollback dos valores exatos.
- O backup contém segredos e deve ficar fora do webroot/repositório, com acesso restrito e depois ser eliminado conforme a política da empresa.
- O dry-run local encontrou zero registros legados em texto puro.

## Provider config e autorização

As rotas de configuração exigem JWT e resolvem a empresa pelo `owner_id`; as consultas e alterações usam `company_id`. Segredos não são retornados, apenas presença/máscara. `provider_config` é devolvido ao dono porque alimenta o formulário de edição, mas não é exposto em rotas públicas/webhooks.

CPF/CNPJ do beneficiário, agência, conta, convênio e chave PIX continuam em `provider_config`, pois não são credenciais de autenticação. Devem ser tratados como dados pessoais/operacionais: acesso somente ao dono/backend, TLS, backup protegido e retenção controlada. Não foram copiados para respostas que não sejam a tela autenticada de configuração bancária.

## mTLS duplicado

O wrapper de materialização/limpeza continua repetido nos connectors. Não foi refatorado porque isso tocaria todos os providers imediatamente antes da homologação e não corrigiria comportamento. A extração para um executor compartilhado deve ocorrer depois dos primeiros testes reais, com testes de limpeza de arquivos e certificado expirado.

## Migrations e DDL runtime

- Banco limpo: o schema canônico contém `dedupe_key` e o índice único.
- Banco existente: a migration incremental foi aplicada com sucesso.
- Reexecução: a migration nova foi executada novamente sem erro.
- As migrations históricas não formam hoje um runner versionado completo; o caminho limpo oficial é `mysql_schema.sql`, enquanto migrations são upgrades de baselines específicos.
- `cobx_gateway_ensure_schema` ainda executa DDL defensivo em runtime. Plano: criar tabela `schema_migrations`, registrar checksums, mover todo DDL para um comando de deploy, monitorar uma versão e então remover gradualmente os `ALTER/CREATE` do request web. Não foi removido agora para não quebrar instalações antigas.

## Fila: estado validado

- claim atômico: mantido;
- recovery de `processing`: 30 minutos;
- backoff: exponencial de 30 segundos até 1 hora;
- máximo de tentativas: 1–10, com criação financeira forçada a 1 antes da homologação;
- job ativo concorrente: retorna o mesmo ID;
- job concluído/falhado: libera a chave;
- cancelamento repetido: não é convertido genericamente em sucesso; cada provider deve confirmar “já cancelado” na homologação;
- timeout: não provoca nova criação automática.

## Riscos restantes

1. Implementar e validar consulta pós-timeout banco por banco antes de elevar `generate_charge` acima de uma tentativa.
2. Testar callbacks e cadeias mTLS reais no proxy de homologação.
3. Confirmar semântica de 409/422 e “já cancelado” por provider.
4. Criar runner versionado de migrations e remover DDL de runtime gradualmente.
5. Ampliar estados locais para estorno, devolução, chargeback e pagamento parcial em uma tarefa própria.
6. Executar sandbox/homologação somente quando o titular fornecer credenciais, certificados e contratos.
