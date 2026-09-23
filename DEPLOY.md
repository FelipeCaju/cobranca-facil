# Instalação em outro domínio ou subdomínio

## Instalador web (recomendado)

1. Envie o projeto completo para o servidor (inclua a pasta `install/`).
2. Antes de empacotar para hospedagem **sem Node**, no PC execute:
   ```bash
   npm run package:installer
   ```
   Isto gera `install/presets/dist-root` e `install/presets/dist-subfolder`.
3. No PC, instale dependências de email (obrigatório para SMTP / teste de email):
   ```bash
   cd api && composer install --no-dev
   ```
   No Laragon: `powershell -File scripts\install-api-deps.ps1`  
   Envie a pasta **`api/vendor/`** no FTP se o servidor não tiver Composer.
4. Abra no browser: `https://seu-dominio.com/install/`
5. Preencha MySQL, URL, tipo de instalação (raiz ou `/cobx/`) e conta admin.
6. Clique em **Instalar agora** — cria `.env`, importa tabelas, gera/copia o `dist/`, tenta `composer install` em `api/`.
7. Apague ou renomeie a pasta `install/` após concluir.

Se o servidor tiver **Node.js + npm**, o instalador tenta `npm run build` automaticamente.

---

# Instalação manual (sem instalador web)

Cada instalação (cliente, ambiente, subdomínio) precisa de **três coisas alinhadas**:

1. **Base do frontend** (`VITE_BASE_PATH`) — caminho onde o React é servido  
2. **`APP_URL` no `.env`** — URL pública (webhooks, emails, cron)  
3. **Base de dados MySQL** própria (importar `database/mysql_schema.sql` + migrações)

O build do Vite **grava o caminho nos ficheiros** (`/assets/...` ou `/cobx/assets/...`). Por isso é preciso **gerar o build certo** antes de enviar os ficheiros.

---

## Qual comando de build usar?

| Onde o site abre no browser | Comando (na pasta do projeto) | `APP_URL` no `.env` do servidor |
|----------------------------|-------------------------------|----------------------------------|
| Raiz do domínio ou subdomínio dedicado | `npm run build:root` | `https://cobrefacil.digitalavance.com.br` |
| Subpasta (ex.: Laragon `/cobx/`) | `npm run build:subfolder` | `https://dominio.com/cobx` |

**Subdomínio na raiz** (ex.: `https://app.empresa.com`) → trate igual à **raiz**: use `build:root`.

**Subdomínio apontando para subpasta** no servidor (ex.: `https://dominio.com/app`) → use `build:subfolder` com base `/app/` (ver secção personalizada abaixo).

---

## Passo a passo (nova instalação)

### 1. Na sua máquina — gerar o frontend

```bash
npm install
npm run build:root
# ou, se for subpasta:
# npm run build:subfolder
```

### 2. Enviar para a hospedagem

- `index.php`, `.htaccess`, `api/`, `favicon.png`
- Conteúdo de **`dist/`** (ou pasta `dist/` inteira, conforme o painel)
- **`.env`** na raiz (criar no servidor; não usar o `.env` de outro site)

### 3. `.env` no servidor (exemplo raiz)

```env
DB_HOST=localhost
DB_DATABASE=nome_da_base
DB_USERNAME=user_mysql
DB_PASSWORD="senha_com_#_use_aspas"

JWT_SECRET=segredo_longo_unico_neste_servidor

APP_URL=https://novo.subdominio.com.br
```

Sem barra no final em `APP_URL`.

### 4. Base de dados

Importar no phpMyAdmin, por ordem:

1. `database/mysql_schema.sql`
2. Ficheiros `database/migration_*.sql` (como em `scripts/install-local.ps1`)

### 5. Testar

- `https://SEU-DOMINIO/` — página carrega  
- `https://SEU-DOMINIO/login` — mesmo aspeto que a raiz (sem `\n` no topo, favicon visível)  
- `https://SEU-DOMINIO/assets/index-XXXXX.js` — devolve **JavaScript** (não HTML)  
- `https://SEU-DOMINIO/favicon.png` — devolve imagem  
- `https://SEU-DOMINIO/api/theme` — devolve JSON  

**Não use** `https://SEU-DOMINIO/dist/index.html` no browser — essa URL redireciona para `/` de propósito.  
Se `/` falhar mas `/dist/index.html` parecer “certo”, o servidor ainda não tem o `index.php` e `.htaccess` atualizados ou o `dist/` está desatualizado.

### Como o site é servido (importante)

| URL | O que o servidor faz |
|-----|----------------------|
| `/`, `/login`, `/dashboard`… | `index.php` envia o ficheiro `dist/index.html` (SPA) |
| `/assets/...` | ficheiros em `dist/assets/` |
| `/favicon.png` | `dist/favicon.png` |
| `/dist/index.html` | redireciona 301 para `/` (não usar) |

Todas as rotas do React devem passar por `index.php`, com o **mesmo** `dist/index.html` do build.

---

## Subpasta personalizada (ex.: `/minhaapp/`)

```bash
npm run build -- --base /minhaapp/
```

No `.env` do servidor:

```env
APP_URL=https://dominio.com/minhaapp
```

Confirme que o `.htaccess` está na pasta correta (raiz da instalação).

---

## Resumo

| Instalação | Build | APP_URL |
|-----------|-------|---------|
| `cobrefacil.digitalavance.com.br` (raiz) | `npm run build:root` | `https://cobrefacil.digitalavance.com.br` |
| `localhost/cobx` (Laragon) | `npm run build:subfolder` | `http://localhost/cobx` |
| Novo subdomínio na raiz | `npm run build:root` | URL completa do novo subdomínio |

**Não reutilize a pasta `dist/` de outro domínio** sem refazer o build com o `VITE_BASE_PATH` correto.
