# Evolução da plataforma de cobranças

Atualizado em 23/09/2026. A aplicação ativa de desenvolvimento está em `C:\xampp\htdocs\cobx-test` e usa a base local `cobx_test_repaired`; a antiga `cobx_test`, inconsistente no InnoDB, foi preservada.

## Estado dos sete itens

| Item | Estado | Implementação |
| --- | --- | --- |
| 1. Múltiplas contas | Concluído | CRUD Asaas/Mercado Pago, conta padrão e seleção na cobrança. |
| 2. PIX + boleto | Implementado | PIX nos dois conectores; boleto Asaas; URL, PDF, linha digitável, QR Code, copia-e-cola e status persistidos. Homologação externa requer credenciais sandbox válidas. |
| 3. Conectores | Concluído para os provedores atuais | Ponto central de capacidades, criação, consulta, cancelamento, webhook e baixa. |
| 4. Segurança/confiabilidade | Concluído no código | Segredos cifrados com AES-256-GCM, migration dos dados legados, validação oficial dos webhooks Asaas/Mercado Pago, idempotência, auditoria e fila persistente com retentativa exponencial. A ativação exige cadastrar o segredo obtido em cada provedor. |
| 5. Importação | Concluído | Upload CSV/XLSX, pré-validação, confirmação, clientes/cobranças e erros por linha. |
| 6. Régua de cobrança | Concluído | Modelos por canal, dias antes/no dia/depois, envio manual, histórico e retentativa WhatsApp. |
| 7. Portal do pagador | Concluído | Link HMAC com expiração, página pública, situação, vencimento, valor, boleto/pagamento e cópia do código. |

## Formatos de importação

Clientes:

```text
nome;email;telefone;documento;cidade;estado
Maria Silva;maria@exemplo.com;5511999999999;12345678909;São Paulo;SP
```

Cobranças:

```text
cliente_email;produto;conta;metodo;vencimento
maria@exemplo.com;Mensalidade;Asaas principal;boleto;2026-10-10
```

Aceita CSV (vírgula ou ponto e vírgula) e XLSX (primeira aba). `conta` vazia usa a padrão. Cliente e produto devem existir antes da importação de cobranças.

## Segurança operacional

- Defina `APP_ENCRYPTION_KEY` com pelo menos 32 bytes aleatórios em produção; sem ela, usa-se `JWT_SECRET` por compatibilidade.
- Dados legados em texto puro continuam legíveis e são cifrados ao serem salvos novamente.
- Produção exige HTTPS e credenciais separadas por ambiente. Nunca versione a `.env`.

## Validações executadas

- PHP 8.2: lint de todos os arquivos sem erros.
- ESLint: zero erros; nove avisos antigos de Fast Refresh.
- Vite: build de produção concluído.
- API: cadastro, duas contas, uma padrão, cliente, produto, cobrança boleto, parcela e portal assinado.
- Criptografia: valor no banco com prefixo `enc:v1:` e sem o texto original.
- CSV: prévia identificou uma linha válida e uma inválida; a execução importou apenas a válida.
- XLSX: modelos reais gerados, renderizados e validados; o modelo de clientes passou pela pré-validação da API.
- Webhooks: requisição sem token retornou 401, token Asaas válido foi aceito e repetição foi identificada como duplicada.
- Fila: tarefa com falha foi preservada, teve `attempts=1` e foi reagendada como `pending` com o erro registrado.
- Chave: `APP_ENCRYPTION_KEY` independente gerada; cinco segredos foram recifrados e o `.env` anterior foi preservado em backup local.
- HTTP: página inicial, bundle novo e rota do portal respondendo 200.

## Homologação bancária

O código está pronto, mas a emissão bancária real não pode ser confirmada com tokens fictícios. O teste local confirmou que uma falha externa é informada sem perder a cobrança. Para homologar PIX e boleto, cadastre tokens sandbox válidos.

## Fechamento técnico de 23/09/2026

- `CobxPaymentConnector` passou a exigir criação, consulta, cancelamento e normalização para todo provedor.
- Asaas e Mercado Pago possuem adaptadores concretos registrados numa fábrica única.
- Criação, sincronização e cancelamento bancário são tarefas persistentes com retentativa exponencial.
- `charge_audit_log` registra criação, importação, alteração, cancelamento, baixa manual e baixa por webhook, com estados anterior/posterior e metadados.
- O histórico pode ser consultado em `GET /api/company/charges/{id}/audit`.
- Parcelas aceitam `receipt_url`; o portal abre o comprovante bancário ou gera um comprovante imprimível para baixas manuais.
