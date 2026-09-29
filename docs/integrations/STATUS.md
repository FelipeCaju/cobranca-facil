# Status das integrações bancárias

Atualizado em 29/09/2026. Nenhum banco novo foi implementado nesta etapa.

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

### Sicoob
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

### Sicredi
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

### Integrações existentes

- [x] Asaas — PIX e boleto preservados
- [x] Mercado Pago — PIX preservado

## Pendências deliberadas

- Implementar um banco por vez, somente após revisão da documentação oficial vigente.
- Definir o teste remoto não destrutivo de Asaas e Mercado Pago antes de habilitá-lo como teste efetivo; hoje o contrato retorna “não suportado” sem criar cobrança.
- Avaliar uma expansão separada dos estados locais para estorno, devolução, chargeback, rejeição e pagamento parcial. Os valores originais já são preservados.
- Homologação real depende de credenciais e certificados fornecidos pelo titular de cada conta.
