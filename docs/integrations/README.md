# Infraestrutura compartilhada de integrações bancárias

Para a mensageria WhatsApp, consulte também [EVOLUTION-WHATSAPP.md](EVOLUTION-WHATSAPP.md). O guia separa Evolution GO da Evolution API tradicional e documenta autenticação, rotas, segurança e diagnóstico reutilizável.

Esta camada prepara o CobrançaFácil para bancos tradicionais sem implementar nenhum banco novo. Asaas e Mercado Pago continuam usando `CobxPaymentConnector` e as colunas legadas, agora espelhadas em um cofre extensível.

## Componentes

| Arquivo | Responsabilidade |
|---|---|
| `api/lib/bank_provider_registry.php` | Fonte central de metadata, campos, disponibilidade e capacidades dos providers. |
| `api/lib/bank_account_store.php` | Cofre JSON criptografado de credenciais e configuração não secreta por provider. |
| `api/lib/bank_certificate_manager.php` | Validação, metadata e armazenamento criptografado de PEM, CRT/KEY e PFX/P12. |
| `api/lib/oauth_token_manager.php` | Obtenção e cache criptografado de OAuth, respeitando `expires_in` e a estratégia do conector. |
| `api/lib/bank_http_client.php` | HTTPS, mTLS, Bearer, Basic, headers, timeout e retry condicionado à idempotência. |
| `api/lib/bank_idempotency.php` | Referência de parcela, chave determinística e txid candidato. |
| `api/lib/payment_connector.php` | Contrato existente e interfaces complementares de capabilities/teste. |
| `api/routes/webhooks.php` | Entrada única, validação por provider, deduplicação, auditoria e enfileiramento. |
| `api/lib/integration_queue.php` | Processamento assíncrono de criação, consulta, cancelamento, mensagem e webhook. |

## Persistência

`payment_accounts` mantém `api_key`, `public_key` e `webhook_secret` para compatibilidade, e passa a oferecer:

- `credentials_encrypted`: JSON cifrado com AES-256-GCM;
- `provider_config`: JSON operacional não secreto;
- `token_cache_encrypted`: token OAuth cifrado;
- `token_expires_at`: validade fornecida pelo banco.

Certificados ficam em `payment_account_certificates`. Certificado, chave privada, cadeia e senha são cifrados individualmente. Somente formato, fingerprint, validade e data de atualização podem ser devolvidos ao frontend.

As parcelas preservam `provider_status`, `provider_event`, `payment_origin`, `txid` e `provider_reference`, além do estado local normalizado.

Migration para instalações existentes:

```text
database/migrations/20260929_banking_infrastructure.sql
```

O schema de instalação nova já contém a estrutura consolidada.

## APIs internas da empresa

- `GET /api/company/payment-providers`: catálogo público de providers e campos.
- `GET|POST /api/company/payment-settings`: listar/criar contas.
- `PUT|DELETE /api/company/payment-settings/{id}`: atualizar/remover conta.
- `POST /api/company/payment-settings/{id}/test`: teste não destrutivo definido pelo conector.
- `GET|PUT|DELETE /api/company/payment-settings/{id}/certificate`: metadata, substituição e remoção do certificado.

As rotas exigem autenticação, empresa ativa e assinatura válida. Segredos nunca são retornados.

## Como um conector futuro utiliza a camada

1. Revisar documentação oficial e registrar pendências no `STATUS.md`.
2. Adicionar a metadata desabilitada/validada no registry e só habilitar após existir conector.
3. Implementar `CobxPaymentConnector`.
4. Implementar `CobxConnectorCapabilities` e, quando houver endpoint seguro, `CobxConnectionTestable`.
5. Obter credenciais com `cobx_bank_account_hydrate()`.
6. Se necessário, obter material do `CobxBankCertificateManager`, criar arquivos temporários, chamar o HTTP client e executar `cleanup()` em `finally`.
7. Fornecer ao `CobxOAuthTokenManager` a estratégia oficial do provider: URL, grant, autenticação, scopes, headers e mTLS.
8. Usar a idempotência oficial do banco; a chave compartilhada é apenas a base determinística.
9. Normalizar o estado local sem descartar estado/evento/origem remotos.
10. Validar webhook no conector; a infraestrutura comum cuida do tenant, deduplicação, auditoria e fila.

## Compatibilidade e decisões deliberadas

- Nenhum banco planejado está habilitado.
- O provider deixou de ser `ENUM` e virou `VARCHAR(48)` para evitar uma migration por instituição.
- Configurações exclusivas não viram colunas globais.
- POST sem idempotência não é repetido automaticamente pelo HTTP client.
- Asaas e Mercado Pago ainda não possuem um endpoint de teste não destrutivo homologado no projeto; o botão informa “não suportado” e não cria cobrança.
- Estados financeiros adicionais não foram criados nesta etapa. A informação remota já é preservada para permitir uma evolução separada e segura.

## Testes

```powershell
C:\xampp82\php\php.exe tests\php\banking_infrastructure_smoke.php
C:\xampp82\php\php.exe tests\php\queue_smoke.php
npm run lint
npx vite build --base /cobx-test/
```

O teste compartilhado verifica catálogo, compatibilidade dos conectores atuais, criptografia, idempotência, bloqueio de HTTP sem TLS e presença do schema.
