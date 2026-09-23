# Cobx — CobrançaFácil

Sistema de cobranças e lembretes: **frontend React (shadcn)** + **API PHP + MySQL** (Laragon).

## Requisitos

- [Laragon](https://laragon.org/) com **Apache**, **PHP 8.x** e **MySQL**
- [Node.js](https://nodejs.org/) (para `npm run dev` / `npm run build`)
- Projeto em `C:\laragon\www\cobx` → **http://localhost/cobx/**

```powershell
npm install
npm run build    # produção (Apache)
npm run dev      # desenvolvimento → http://localhost:8080/cobx/
```

## Instalação

```powershell
powershell -ExecutionPolicy Bypass -File scripts\install-local.ps1
```

## Abrir no browser

| Situação | URL |
|----------|-----|
| Laragon com **Apache** ligado | **http://localhost/cobx/** |
| Usava o Vite na porta **8080** | **http://localhost:8080/** (ver abaixo) |

Se aparecer **`ERR_CONNECTION_REFUSED`**, nada está a escutar nessa porta:

1. **Laragon** → botão **Start All** (Apache + MySQL), depois abra **http://localhost/cobx/**
2. **Ou** suba o servidor PHP na 8080 (como o `npm run dev` antigo):

```powershell
powershell -ExecutionPolicy Bypass -File scripts\start-web.ps1
```

Depois abra **http://localhost:8080/login**

## Utilizadores demo

| Email | Senha |
|-------|--------|
| `usuario.starter@cobx.local` | `UsuarioStarter@2026` |
| `usuario.pro@cobx.local` | `UsuarioPro@2026` |
| `superadmin@cobx.local` | `SuperAdmin@2026` |

## Estrutura

| Pasta / ficheiro | Função |
|------------------|--------|
| `index.php` | Entrada da aplicação web |
| `app/` | Páginas, vistas e lógica do painel (sessão PHP) |
| `api/` | API JSON (webhooks, cron, integrações) |
| `database/` | Schema e seeds MySQL |
| `public/assets/` | CSS da interface |
| `.env` | Base de dados, JWT, `APP_URL` |

## Configuração (`.env`)

```
APP_URL=http://localhost/cobx
DB_HOST=127.0.0.1
DB_DATABASE=cobx
DB_USERNAME=root
DB_PASSWORD=
JWT_SECRET=...
```

## Cron de lembretes

```
GET http://localhost/cobx/api/cron/run
Header: X-Cron-Secret: <segredo em Config. master>
```

## API (webhooks)

- `POST /cobx/api/webhooks/mercadopago?company_id=...`
- `POST /cobx/api/webhooks/asaas?company_id=...`

Autenticação do painel: sessão PHP (web) ou JWT (`/api/auth/login`) para integrações.
