# Status das integrações bancárias

Atualizado em 29/09/2026. Provider Banco Inter implementado localmente; homologação remota permanece pendente de credenciais e certificado reais.

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

- Validar o Inter no sandbox e na homologação do titular antes de marcar o provider como concluído operacionalmente.
- Definir o teste remoto não destrutivo de Asaas e Mercado Pago antes de habilitá-lo como teste efetivo; hoje o contrato retorna “não suportado” sem criar cobrança.
- Avaliar uma expansão separada dos estados locais para estorno, devolução, chargeback, rejeição e pagamento parcial. Os valores originais já são preservados.
- Homologação real depende de credenciais e certificados fornecidos pelo titular de cada conta.
