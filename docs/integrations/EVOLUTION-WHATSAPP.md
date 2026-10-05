# Evolution WhatsApp — guia reutilizável

Atualizado em 05/10/2026. Este guia descreve o contrato usado pelo CobrançaFácil e pode ser reutilizado em outros projetos. Não contém chaves, tokens ou números conectados.

## Produtos diferentes

| Produto | Implementação | Rotas características | Referência |
|---|---|---|---|
| Evolution API | Node.js/Baileys e Cloud API | `/instance/fetchInstances`, `/instance/connectionState/{instance}`, `/message/sendText/{instance}` | `https://doc.evolution-api.com/` |
| Evolution GO | Go/whatsmeow | `/server/ok`, `/instance/all`, `/instance/status`, `/send/text` | Swagger da instalação em `/swagger/index.html` |

Os contratos não são intercambiáveis. O CobrançaFácil usa uma chamada somente leitura: se `GET {baseUrl}/server/ok` responder com sucesso, seleciona GO; caso contrário preserva o adapter da Evolution API tradicional. Nunca detecte o produto tentando enviar mensagem, pois isso pode duplicar envios.

## URL base

Configure apenas a origem, por exemplo `https://evolution.exemplo.com.br`. `/manager` é a interface administrativa e `/swagger/index.html` é a documentação, não a base REST. O adapter remove automaticamente `/manager/...` e `/swagger/...`.

## Autenticação Evolution GO

Todas as operações autenticadas usam o header `apikey`.

### Chave global

Configurada no servidor como `GLOBAL_API_KEY` e disponível somente no backend. Administra instâncias: listar, criar, consultar metadados e excluir. Não deve ir ao navegador.

### Token individual

Definido ao criar cada instância. É usado para conectar, consultar estado, obter QR, logout/reconexão, envio e configuração de eventos daquela sessão.

Persistência no CobrançaFácil:

- chave global: `master_settings.evolution_master_api_key`;
- token master: `master_settings.master_whatsapp_instance_token`;
- token da empresa: `companies.whatsapp_token`;
- todos os segredos são criptografados com AES-256-GCM pelos helpers de segredo;
- tokens não são devolvidos à UI nem escritos em logs.

## Endpoints GO usados

Confirme sempre a versão no Swagger da instalação: `{baseUrl}/swagger/index.html` ou `{baseUrl}/swagger/doc.json`.

### Saúde e identificação

```http
GET /server/ok
```

### Listar instâncias — chave global

```http
GET /instance/all
apikey: GLOBAL_API_KEY
```

A resposta contém `data[]`, com campos como `id`, `name`, `token` e `connected`. `token` é segredo. Antes de criar, a aplicação procura uma instância do mesmo nome para evitar duplicação e permitir adoção após restauração.

### Criar — chave global

```http
POST /instance/create
apikey: GLOBAL_API_KEY
Content-Type: application/json

{"name":"nome-estavel","token":"token-aleatorio-forte"}
```

O CobrançaFácil usa `cobx-master` para notificações da plataforma e `cobx{company_uuid_sem_hifens}` para empresas. Tokens são gerados com `random_bytes`.

### Conectar — token individual

```http
POST /instance/connect
apikey: TOKEN_DA_INSTANCIA
Content-Type: application/json

{"subscribe":[],"immediate":true}
```

O contrato também admite `webhookUrl`, `phone`, `rabbitmqEnable`, `websocketEnable` e `natsEnable`. Só envie recursos realmente utilizados.

### Estado — token individual

```http
GET /instance/status
apikey: TOKEN_DA_INSTANCIA
```

Resposta observada: `{"data":{"Connected":false,"LoggedIn":false,"Name":""},"message":"success"}`. O adapter normaliza `Connected=true` para `open` e `false` para `close`.

### QR Code — token individual

```http
GET /instance/qr
apikey: TOKEN_DA_INSTANCIA
```

Se a sessão ainda não iniciou, o adapter chama `/instance/connect` uma vez e consulta novamente. O parser aceita QR em `data.qrcode`, `data.base64`, `qrcode.base64`, `code` e pairing code.

### Logout — token individual

```http
DELETE /instance/logout
apikey: TOKEN_DA_INSTANCIA
```

Logout encerra o vínculo, mas a instância administrativa pode permanecer. A próxima criação procura o mesmo nome e reutiliza a instância.

### Texto — token individual

```http
POST /send/text
apikey: TOKEN_DA_INSTANCIA
Content-Type: application/json

{"number":"5511999999999","text":"Mensagem","delay":1200}
```

O telefone é reduzido a dígitos; números brasileiros com 10 ou 11 dígitos recebem DDI 55. O adapter faz uma única tentativa de formato GO. Após timeout, não alterna payloads, evitando duplicação silenciosa.

### Mídia por URL — token individual

```http
POST /send/media
apikey: TOKEN_DA_INSTANCIA
Content-Type: application/json

{"number":"5511999999999","type":"image","url":"https://exemplo.com/qr.png","caption":"QR Code PIX","filename":"qrcode-pix.png"}
```

O `MediaStruct` da instalação exige URL. Base64 não é presumido. Quando o QR só existe em base64, o sistema mantém o PIX copia e cola como fallback textual.

## Evolution API tradicional preservada

O adapter anterior continua disponível para instalações Node:

- `POST /instance/create` com `instanceName`, `integration=WHATSAPP-BAILEYS` e `qrcode=true`;
- `GET /instance/connectionState/{instance}`;
- `GET /instance/connect/{instance}`;
- `DELETE /instance/logout/{instance}`;
- `POST /message/sendText/{instance}`;
- `POST /message/sendMedia/{instance}`.

Algumas instalações expõem essas rotas sob `/api`; o fallback só é usado após `404`. Ele nunca é misturado com o fluxo GO.

## Fluxo do CobrançaFácil

1. Administrador salva URL e chave global.
2. Backend normaliza URL e criptografa a chave.
3. Produto é detectado com rota somente leitura.
4. Ao criar, gera nome estável e token aleatório.
5. Na GO, procura instância existente antes de criar.
6. Token individual é criptografado e associado ao tenant.
7. Backend conecta e devolve somente QR/estado à UI.
8. Usuário escaneia o QR.
9. Status e envios usam token individual.
10. Lembretes e notificações passam pelo mesmo adapter.

## Segurança e confiabilidade

- HTTPS válido em produção.
- Chave global somente no backend; nunca em JavaScript, query string ou documentação.
- Um token diferente por instância e tenant.
- Segredos mascarados na interface e excluídos de logs.
- Timeouts limitados; não repetir `POST /send/*` cegamente após timeout.
- Registrar entregas na fila do sistema.
- Webhooks GO não devem ser considerados assinados sem confirmação no Swagger/versão instalada; usar URL/header secreto e deduplicar pelo ID remoto.
- Fazer backup do vínculo `company_id -> instance name -> token criptografado`.
- A rotação de `APP_ENCRYPTION_KEY` precisa incluir os tokens de instância.

## Diagnóstico seguro

1. `GET /server/ok`: rede, TLS e produto.
2. `GET /instance/all` com chave global: autenticação administrativa.
3. Confirmar nome e token da instância.
4. `GET /instance/status` com token individual.
5. `POST /instance/connect` se necessário.
6. `GET /instance/qr` e escanear.
7. Repetir status até `Connected=true`.
8. Só então enviar teste para número autorizado.

| Sintoma | Interpretação provável |
|---|---|
| `/manager` retorna HTML | normal: é o painel |
| `/instance/all` 200 e `/instance/fetchInstances` 404 | servidor GO |
| 401/403 em `/instance/all` | chave global inválida |
| 401/403 em `/instance/status` | chave global usada no lugar do token, ou token errado |
| `/instance/qr` 400 | consultar status e iniciar conexão antes |
| timeout em envio | verificar resultado antes de repetir |

## Limites e fontes

Nesta implementação nenhuma mensagem real foi enviada e nenhum telefone foi conectado automaticamente. Webhook de entrada não foi habilitado, pois o uso atual é saída de notificações/cobranças.

Fontes oficiais:

- `https://github.com/evolution-foundation/evolution-go`
- Swagger da instalação: `{baseUrl}/swagger/index.html`
- JSON Swagger: `{baseUrl}/swagger/doc.json`
- `https://doc.evolution-api.com/`
- `https://github.com/evolution-foundation/evolution-api`
- `https://github.com/evolution-foundation/docs-evolution`
