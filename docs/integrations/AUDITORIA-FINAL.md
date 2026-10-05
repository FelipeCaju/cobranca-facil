# Auditoria final da camada de integrações bancárias

**Data:** 29/09/2026
**Escopo:** contrato `CobxPaymentConnector`, infraestrutura comum, 11 providers, cadastro de contas, banco de dados, filas, webhooks e testes.
**Regra aplicada:** nenhuma pendência dependente de documentação, credencial, sandbox ou homologação foi completada por suposição.

## 1. Visão geral

### Crítico

- **Criação remota após timeout ainda exige estratégia por banco.** O cliente HTTP repetia `POST` quando existia apenas um header genérico `Idempotency-Key`, sem prova de que cada banco o reconhecia. A repetição HTTP de `POST` foi desativada. Porém, uma queda depois de o banco criar a cobrança e antes de salvar `external_id` ainda pode fazer a fila executar novamente. A correção definitiva depende de confirmar, por produto, header/campo oficial ou consulta por referência antes de repetir.
- **Não há homologação real.** Nenhum provider bancário pode ser classificado como operacionalmente concluído sem credenciais, certificado, sandbox e evidências do titular. O `STATUS.md` mantém essa separação.

### Importante

- A conciliação por referência de cobrança baixava a primeira parcela quando o valor era zero, inexistente ou ambíguo. Corrigido: sem total ou correspondência única, nenhuma parcela é baixada.
- `provider_event` não era persistido na baixa por webhook. Corrigido.
- Payload bruto de webhook, potencialmente contendo CPF/CNPJ e dados do pagador, era armazenado em texto aberto. Corrigido para AES-256-GCM, com leitura retrocompatível dos registros antigos.
- Santander e Bradesco aceitavam callback apenas pelo formato do JSON; Itaú aceitava qualquer `Authorization` não vazio. Corrigido: callback requer mTLS confirmado pelo terminador TLS ou token configurado e comparado em tempo constante. A reconsulta remota continua obrigatória nesses conectores.
- Jobs que ficavam em `processing` após interrupção não eram recuperados. Corrigido com recuperação após 30 minutos.
- A migration de 24/09 repetia coluna e tabela já criadas em 23/09 e podia interromper uma instalação sequencial. Corrigido com `IF NOT EXISTS`.
- As filas não têm chave única lógica por operação. Cliques/rotinas concorrentes podem enfileirar mais de um `generate_charge`, `cancel_charge` ou `send_installment`. O estado local reduz parte do risco, mas não elimina a janela concorrente.
- `provider_config` não é secreto, mas inclui CPF/CNPJ do beneficiário, agência, conta, convênio e chave PIX. Esses dados não são credenciais, porém são dados pessoais/operacionais e precisam de acesso administrativo restrito, backup protegido e política de retenção.

### Melhoria opcional

- O bloco que materializa certificado, executa callback e remove arquivos temporários está repetido nos conectores mTLS. É duplicação relevante de manutenção, mas não foi refatorada nesta auditoria para não alterar providers sem necessidade funcional.
- Capabilities existem no catálogo e nos próprios conectores. Hoje os testes cobrem parte da coerência, mas uma única fonte ou um teste de igualdade completo reduziria drift.
- Os arquivos dos conectores são excessivamente compactados em linhas longas. Isso prejudica revisão, mas é estética/manutenibilidade e não justificou refatoração isolada.

### Nenhum problema encontrado

- Não foram criadas colunas globais específicas de Inter, Sicoob, Sicredi, Santander, Itaú, BB, Bradesco, CAIXA ou C6.
- BB e C6 permanecem bloqueados, sem endpoints inventados.
- CAIXA mantém XML/SOAP encapsulado no adapter próprio.
- Asaas e Mercado Pago mantêm os métodos anunciados (`pix`/`boleto` e `pix`, respectivamente).

## 2. Infraestrutura comum

| Componente | Resultado | Observação |
|---|---|---|
| `OAuthTokenManager` | Reutilizado corretamente | Cache por conta/configuração, token criptografado, escopo/grant configuráveis e `expires_in` respeitado. Falta teste concorrente de renovação. |
| `CertificateManager` | Reutilizado corretamente | PEM/CRT+KEY/PFX, metadata, criptografia, permissões temporárias e limpeza. A seleção interna é por `payment_account_id`; os chamadores precisam continuar resolvendo a conta por `company_id` antes. |
| `BankHttpClient` | Reutilizado pelos bancos | HTTPS, timeout, mTLS, JSON/form/XML e respostas normalizadas. Retry de `POST` genérico foi bloqueado; falta telemetria sanitizada/rate-limit central. |
| `CredentialVault` | Reutilizado corretamente | AES-256-GCM e bundle criptografado. Dados legados em texto puro continuam legíveis, portanto ainda precisam de migração/regravação. |
| Webhooks | Comum e assíncrono | Raw body, tenant, validação por connector, auditoria, fila e deduplicação. Payload passou a ser criptografado. |
| Idempotência | Parcial | TXID/referência/chave são determinísticos, mas a segurança de retentativa de criação ainda precisa ser comprovada por banco. |
| Provider metadata | Reutilizada | Formulário é orientado pelo catálogo; particularidades ficam em `credentials`/`provider_config`. Existe duplicação com `capabilities()` do conector. |

## 3. Banco por banco

| Provider | Implementação local | Dependências externas e pendências reais | Riscos/duplicação | Aderência comum |
|---|---|---|---|---|
| Inter | Cob, CobV, boleto híbrido, consulta, cancelamento, PDF/copia-e-cola e webhook | Credenciais, mTLS, conta PJ, sandbox/homologação e confirmação dos retornos | Webhook confia no mTLS do proxy e no payload; operação real não testada. Wrapper mTLS duplicado | Alta |
| Sicoob | PIX Cob/CobV, Cobrança v3, boleto/híbrido opcional, consulta/baixa e webhook | Token de sandbox ou OAuth/mTLS, contrato, scopes, callback e modalidade | Autenticações por produto precisam de teste real. Wrapper mTLS duplicado | Alta |
| Sicredi | PIX e Cobrança separados, boleto/híbrido, consulta, baixa e webhooks | URLs de homologação PIX, produção para recursos indisponíveis em sandbox, credenciais e contrato | PIX recebido sem Nosso Número usa payload após mTLS; boleto faz reconsulta. Wrapper mTLS duplicado | Alta |
| Santander | PIX, boleto/BolePix, Workspace, consulta, instrução, webhook e reconsulta | Workspace/convênio, credenciais/certificado, callback e sandbox reais | Callback agora exige mTLS ou token configurado. Endpoint/payload dependem de homologação. Wrapper mTLS duplicado | Alta |
| Itaú | PIX, boleto/Bolecode, sandbox separado, produção OAuth+mTLS, consulta/cancelamento e reconsulta | URL produtiva de Cobrança, onboarding, certificado e webhook reais | Aceitação de qualquer Authorization foi corrigida; agora mTLS ou segredo exato. Wrapper mTLS duplicado | Alta |
| Banco do Brasil | Estrutura segura e connector bloqueado | `PENDENTE DE DOCUMENTAÇÃO BB`: OpenAPI, OAuth, endpoints, schemas e eventos | Nenhuma operação financeira exposta; parser retorna zero eventos | Adequada ao bloqueio |
| Bradesco | PIX imediato, consulta, cancelamento, recebidos e reconsulta | CobV/boleto/PIX-boleto, token produtivo, callback e sandbox | Callback permissivo foi corrigido; boleto não é anunciado. Wrapper mTLS duplicado | Alta no PIX |
| CAIXA | SIGCB boleto convencional/híbrido, consulta, baixa e adapter XML | Convênio, homologação, origem da liquidação híbrida e API PIX avulsa | Sem webhook no contrato revisado; origem híbrida permanece explicitamente indeterminada | Alta |
| C6 | Estrutura bloqueada e sem métodos anunciados | `PENDENTE DE DOCUMENTAÇÃO C6`: autenticação, endpoints, scopes, certificado, webhook e schemas | Nenhum endpoint especulativo | Adequada ao bloqueio |
| Asaas | PIX, boleto, consulta, cancelamento e webhook por token | Conta sandbox e teste não destrutivo | Usa cliente HTTP legado, não `BankHttpClient`; não possui suíte específica completa | Compatível, parcialmente legado |
| Mercado Pago | PIX, consulta, cancelamento, HMAC e reconsulta | Credencial de teste e validação real de assinatura/callback | Usa cliente HTTP legado; não possui suíte específica completa | Compatível, parcialmente legado |

## 4. Banco de dados

- O modelo extensível está correto: segredos em `credentials_encrypted`, particularidades não secretas em `provider_config` e retornos em colunas genéricas da parcela.
- Não há coluna global bancária específica desnecessária.
- `payment_account_certificates` é genérica e relacionada à conta.
- `payment_webhook_events` possui `UNIQUE(company_id, provider, event_key)`, que protege a deduplicação mesmo com corrida entre requests.
- A consulta prévia de deduplicação é redundante, mas útil para resposta rápida; a constraint continua sendo a garantia real.
- A migration redundante de 24/09 foi tornada idempotente. Ainda não existe um runner de migrations com tabela de versões/checksums; a aplicação também executa DDL defensivo em runtime, o que deve ser evitado em produção madura.

## 5. Segurança

- Credenciais, tokens OAuth, certificados, chaves e payloads de webhook novos ficam criptografados com AES-256-GCM.
- A chave deriva de `APP_ENCRYPTION_KEY` e, por compatibilidade, pode cair em `JWT_SECRET`; produção deve usar uma chave de criptografia própria e estável.
- O backend não devolve os valores secretos completos; retorna presença/máscara.
- Certificados temporários usam diretório restrito, arquivos `0600` e limpeza em `finally` nos conectores auditados.
- Contas, tokens, cobranças, filas e webhooks são consultados por `company_id`. O certificado é obtido por ID da conta, mas as rotas validam antes a propriedade da conta.
- `provider_config` é texto aberto e deve receber somente configuração não secreta. CPF/CNPJ, conta e chave PIX ainda exigem proteção operacional/LGPD.
- Não foram encontrados segredos reais versionados nos arquivos analisados.
- A confiança em `SSL_CLIENT_VERIFY` depende de Apache/reverse proxy remover qualquer header equivalente enviado pelo cliente e preencher a variável somente após validar a cadeia do banco.

## 6. Webhooks

- Raw body é usado na verificação e agora armazenado criptografado.
- Asaas usa token; Mercado Pago usa HMAC; os bancos usam mTLS e/ou token conforme configuração disponível.
- Santander, Itaú e Bradesco fazem reconsulta antes da baixa. Sicoob/Sicredi variam por produto. Inter aceita o evento após mTLS oficial.
- A deduplicação usa hash de provider, request ID e corpo, mais índice único por tenant/provider/evento.
- O processamento é assíncrono pela fila.
- A baixa só altera parcelas `pending`/`overdue`; evento repetido não baixa novamente.
- A conciliação ambígua deixou de escolher a primeira parcela.
- `provider_status`, `provider_event`, `payment_origin` e `txid` passam a ser preservados na baixa.
- Eventos não pagos são auditados, mas o domínio local ainda não representa estorno, devolução, chargeback ou pagamento parcial. Essa lacuna já está registrada no `STATUS.md` e não deve ser resolvida por mapeamento para `cancelled`.

## 7. Filas

- Há claim atômico por `UPDATE ... WHERE status='pending'`, retry exponencial de 30 s até 1 h e máximo configurável.
- Jobs abandonados em `processing` agora voltam a `pending` após 30 minutos.
- O timeout HTTP padrão é 30 s, conexão 10 s e limite 120 s.
- `payment_webhook` é idempotente pelo evento auditado/processado e pela condição do status da parcela.
- `sync_installment` é repetível e escopado por tenant.
- `generate_charge` continua sendo o maior risco: não há `dedupe_key` de job nem garantia universal de idempotência remota após timeout. O HTTP não repete mais `POST`, mas a retentativa posterior da fila deve ser habilitada por provider somente após confirmar a estratégia oficial.
- `cancel_charge` é escopado por tenant, mas uma falha parcial pode repetir cancelamentos já executados; os connectors precisam tratar “já cancelado” como sucesso idempotente após homologação.

## 8. Testes

Executados sem chamadas bancárias reais:

- PHP syntax em `api`, `config` e `tests`;
- infraestrutura bancária com banco MariaDB local;
- fila com banco local;
- smoke tests de BB, Bradesco, C6, CAIXA, Inter, Itaú, Santander, Sicoob e Sicredi;
- regressão específica desta auditoria;
- ESLint;
- build Vite de produção.

Limitações da suíte:

- A maioria dos testes de provider é contrato/normalização e inspeção estática; não há servidor HTTP mockado cobrindo timeout, 4xx, 5xx, rate-limit e respostas reais.
- Faltam suítes próprias de Asaas e Mercado Pago.
- Faltam testes de webhook inválido/válido/duplicado/fora de ordem por provider e testes multiempresa adversariais.
- O teste de fila usa o banco local compartilhado e processou jobs já existentes; deve futuramente usar fixture isolada/transação ou database próprio.
- Sandbox, homologação e produção não foram executados.

## 9. Comparação com `STATUS.md`

Divergências objetivas encontradas e tratadas:

1. “Webhook comum, assinado” era amplo demais: nem todo banco oferece assinatura. O texto passou a dizer “validado conforme o provider”.
2. “Preservação de evento remoto” não era verdadeira na baixa por webhook; o SQL foi corrigido.
3. “Migration retrocompatível” era contrariada pela migration duplicada de 24/09; ela foi tornada idempotente.
4. Santander/Bradesco/Itaú tinham validação permissiva apesar das pendências documentadas; agora falham de forma segura sem mTLS ou token configurado.

Nenhum checkbox de sandbox, homologação ou produção foi alterado. BB, C6, Bradesco CobV/boleto, CAIXA PIX avulso e demais pontos pendentes continuam exatamente bloqueados.

## 10. Resultado final

### Correções feitas

- remoção da baixa automática da primeira parcela em conciliação ambígua;
- persistência de `provider_event`;
- criptografia do payload de webhook em repouso;
- endurecimento dos callbacks Santander, Itaú e Bradesco;
- recuperação de jobs abandonados;
- bloqueio de retry HTTP automático de `POST` baseado apenas em header genérico;
- migration redundante idempotente;
- testes de regressão e atualização objetiva do `STATUS.md`.

### Correções não feitas

- **Idempotência remota universal de criação:** depende do contrato oficial de cada produto; não foi inventada.
- **Deduplicação lógica dos jobs:** requer migration e definição de chave/semântica por tipo; recomendada antes de produção.
- **Refatoração do wrapper mTLS duplicado:** útil, mas não necessária para corrigir comportamento agora.
- **Estados financeiros ampliados:** exige decisão de domínio para estorno, devolução, chargeback, rejeição e pagamento parcial.
- **Migração automática de segredos legados:** precisa de operação controlada e backup; a leitura retrocompatível foi preservada.
- **Homologações reais:** dependem do titular, contratos, credenciais e certificados.

### Estado final

O desenho é reutilizável e a infraestrutura comum é realmente usada pelos nove bancos planejados. Inter, Sicoob, Sicredi, Santander, Itaú, Bradesco PIX e CAIXA SIGCB têm implementação local, mas não homologação operacional. BB e C6 estão corretamente bloqueados. Asaas e Mercado Pago permanecem funcionais na arquitetura anterior, integrados à conta múltipla, webhook e fila comuns. Antes de produção, os bloqueadores prioritários são: idempotência de criação por provider, deduplicação lógica da fila, testes HTTP/webhook/multiempresa e homologação real.
