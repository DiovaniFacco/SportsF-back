# Plano — Sistema de Autenticação SportsF: Correções + Cadastro + Recuperação de Senha

**Stack analisada:** Expo (web) em `/home/diovanifacco/front` · Laravel 12 API em `/home/diovanifacco/back` · PostgreSQL · Hospedagem: Railway
**Decisões tomadas:** Resend para e-mails · Remover login com Google por enquanto

---

## 1. Diagnóstico — Estado Atual

### 1.1 O que já está bom ✅

| Item | Onde |
|---|---|
| Mensagem genérica no login (não revela se o e-mail existe) | `AuthController@login` |
| Senha com bcrypt (12 rounds) | `User` cast `hashed` + `BCRYPT_ROUNDS=12` |
| Token em SecureStore no nativo | `secureStorage.js` |
| `role` travado como `CLIENTE` no cadastro (payload do cliente é ignorado) | `AuthController@register` |
| Middleware de role consistente, com bypass de admin | `CheckRole.php` |
| `.env` fora do git | `.gitignore` dos dois repositórios |
| Migrations rodam sozinhas no deploy | `start.sh` → `php artisan migrate --force` |
| Tabela `password_reset_tokens` já existe (migration base) | `0001_01_01_000000_create_users_table.php` |
| **Tela de cadastro JÁ EXISTE** — `RegisterScreen.js` está criada e ligada no `App.js` | `front/src/screens/RegisterScreen.js` |

> **Nota sobre "criar tela de cadastro":** a tela já existe e funciona (nome, e-mail, telefone opcional, senha + confirmação, chama `POST /auth/register`). O que falta é **endurecer** a validação e limpar o caminho fake do Google — não criar do zero.

### 1.2 Problemas CRÍTICOS 🔴 (precisam de correção)

| # | Problema | Detalhe / Onde |
|---|---|---|
| C1 | **"Esqueceu a senha?" é fake** | `LoginScreen.js:187` mostra um `Alert` decorativo. Não existe endpoint de recuperação, nem mailer real (`MAIL_HOST=mailpit` = só dev). |
| C2 | **Rate limit quebrado no Railway (auto-DoS)** | `bootstrap/app.php` não configura `trustProxies`. Atrás do proxy da Railway, `request()->ip()` é o IP **do proxy** → as 6 req/min de login são um balde **compartilhado por todos os usuários**: 7 tentativas de login de pessoas diferentes no mesmo minuto = HTTP 429 para todo mundo. |
| C3 | **`CACHE_STORE=array`** (`.env.example`) | Rate limit, lockout e o token de reset precisam de cache **persistente entre requests**. Com driver `array` nada sobrevive ao fim da requisição. A migration `create_cache_table` já existe — basta usar `database`. |
| C4 | **Tokens Sanctum nunca expiram** | `config/sanctum.php` → `'expiration' => null`. Token vazado = válido **para sempre**. Nenhum cleanup roda (e o scheduler do Laravel não está ativo na Railway + Apache). |
| C5 | **Sem troca de senha / sem revogação de sessões** | Não existe endpoint "alterar senha" para usuário logado; não existe "sair de todos os dispositivos". |
| C6 | **Verificação do Google incompleta** *(moot após remoção, mas registrar)* | `googleAuth` valida o token no endpoint do Google, mas **não confere `aud`** (client ID) nem `email_verified` — aceitaria token emitido para qualquer outro app Google. |

### 1.3 Problemas MÉDIOS 🟡

| # | Problema | Onde |
|---|---|---|
| M1 | **Enumeração de e-mail no cadastro** — 422 "e-mail já existe" confirma quais e-mails têm conta | `register` (`unique:users`) |
| M2 | **CORS `allowed_origins: ['*']`** — sem `config/cors.php`, vale o default do framework | API toda |
| M3 | **Interceptor 401 não atualiza o estado do React** — limpa o storage mas a UI continua "logada" até um refresh | `client.js:48` |
| M4 | **Fallback para dado obsoleto** — `getMeApi` retorna o usuário salvo se a API falhar (com token inválido mostra perfil velho) | `auth.js:96` |
| M5 | **Caminho fake do Google no front** — sem Client IDs configurados (`.env.production` vazio!), o botão envia `email` + `google_id` inventados para o backend, que responde 401. Em produção o botão hoje **só gera erro** | `LoginScreen.js:100-113`, `RegisterScreen.js:114-127` |
| M6 | Sem `confirmed`/regras de senha no backend (só `min:8`); sem medidor de força no front | `register` |
| M7 | Sem `autocomplete`/`textContentType` nos inputs (password managers não reconhecem os campos) | Telas auth |

### 1.4 Risco aceito (documentado, fora do escopo)

- **Token em localStorage na web** (fallback do SecureStore): padrão de SPA com Bearer token; a alternativa (cookie httpOnly cross-domain entre dois services da Railway) adiciona CSRF + CORS credenciado — complexidade que não compensa para um app de reservas. Mitigação: sem `dangerouslySetInnerHTML`, deps mínimas.
- **Enumeração M1**: a mitigação "correta" (resposta 201 sempre + e-mail de aviso) piora UX. Decisão: manter o 422 e confiar no throttle apertado. Reavaliar se o app crescer.
- **Sem verificação de e-mail no cadastro**: a recuperação de senha prova posse do e-mail quando importa (o link só chega na caixa do dono). Manter simples.

---

## 2. Arquitetura do Fluxo de Recuperação (seguro e simples)

```
┌─ Front (web) ──────────────────────────────────────────────────────────┐
│  LoginScreen ──"Esqueceu?"──▶ ForgotPasswordScreen                     │
│    POST /auth/forgot-password {email}                                  │
│    ← SEMPRE 200: "Se o e-mail estiver cadastrado, envie o link."        │
│                                                                        │
│  E-mail chega com link:                                                │
│  https://<front>/redefinir-senha?token=…&email=…                       │
│                                                                        │
│  App.js detecta ?token= no boot (mesmo padrão de /gestao)              │
│    ──▶ ResetPasswordScreen (senha nova + confirmação + força)          │
│         POST /auth/reset-password {token, email, password, …}           │
│         ← 200 { user, access_token }  (revoga TODOS os tokens antigos, │
│            já devolve sessão nova = usuário logado direto)             │
└────────────────────────────────────────────────────────────────────────┘
```

**Segurança do token (padrão Laravel Password Broker, sem invenção):**
- No banco fica só o **hash SHA-256** do token (tabela `password_reset_tokens` que já existe);
- Expira em **60 min**, **uso único** (apagado ao usar), 1 token vigente por e-mail;
- Throttle próprio (3/min) para não virar ferramenta de spam de e-mail;
- Reset bem-sucedido → **revoga todos os tokens Sanctum** do usuário (roubo de sessão via "esqueci a senha" é mitigado);
- Resposta do `forgot-password` sempre idêntica, exista ou não o e-mail (anti-enumeração).

---

## 3. Plano de Implementação

### Fase 1 — Backend: correções críticas de segurança (pré-requisito do fluxo de recuperação)
**Estimativa: ~2–3 h · Sem breaking changes para o app atual**

1. **`trustProxies`** em `bootstrap/app.php`:
   ```php
   ->withMiddleware(function (Middleware $middleware): void {
       $middleware->trustProxies(at: '*'); // Railway termina TLS/proxy na frente
   })
   ```
   → `request()->ip()` volta a ser o IP real do usuário; todo o resto (throttle, lockout) depende disso.

2. **Cache persistente**: `.env` de produção → `CACHE_STORE=database` (migration da tabela já existe; `array` não persiste nada).

3. **Rate limiter nomeado `auth`** no `AppServiceProvider::boot`:
   ```php
   RateLimiter::for('auth', function (Request $request) {
       return [
           Limit::perMinute(10)->by($request->ip()),
           Limit::perDay(60)->by('auth:'.strtolower($request->input('email') ?? $request->ip())),
       ];
   });
   ```
   Aplicar nas rotas públicas de auth em vez do `throttle:6,1` atual.

4. **Lockout por conta no login** (dentro de `AuthController@login`, usando `RateLimiter` + cache): 5 tentativas falhas por `email+ip` → bloqueio de 5 min com resposta 429 e mensagem PT-BR ("Muitas tentativas. Tente novamente em X segundos."). Isso cobre força-bruta distribuída contra um único e-mail.

5. **Expiração de token Sanctum**: `config/sanctum.php`
   ```php
   'expiration' => env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 43200), // 30 dias
   ```
   Cleanup **sem scheduler** (a Railway não roda cron junto do Apache): no `login`, apagar os tokens **expirados do próprio usuário** (`$user->tokens()->where('expires_at', '<', now())->delete()`) — resolve acúmulo na prática.

6. **Troca de senha + revogação** (novos endpoints em `auth:sanctum`):
   - `PUT /auth/password` — exige `current_password` (validar com `Hash::check`), `password` + `password_confirmation` com `Password::min(8)`; em caso de sucesso, **mantém só o token atual** e revoga os demais;
   - `POST /auth/logout-all` — apaga todos os tokens do usuário.

7. **Regras de senha reforçadas** em `register` (e nos endpoints novos): `['required', 'confirmed', Password::min(8)]`.

8. **CORS restrito** — criar `config/cors.php`:
   ```php
   'paths' => ['api/*'],
   'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:8081'))),
   'allowed_origins_patterns' => ['/^https:\/\/([a-z0-9-]+\.)?sfacco\.com\.br$/'],
   'allowed_methods' => ['*'],
   'allowed_headers' => ['*'],
   'supports_credentials' => false,
   ```

9. **Validação**: estender `tests/Feature/AuthTest.php` — lockout, 429 do limiter, troca de senha revogando tokens, token expirado rejeitado.

### Fase 2 — Backend: recuperação de senha (o coração do pedido)
**Estimativa: ~3–4 h · Depende da Fase 1 (throttle/cache)**

1. **Mailer Resend** (Laravel 12 tem transporte nativo):
   - `composer require resend/resend-php`
   - `.env` Railway: `MAIL_MAILER=resend`, `RESEND_API_KEY=…`, `MAIL_FROM_ADDRESS=no-reply@sfacco.com.br`, `MAIL_FROM_NAME="SportsF"`
   - No Resend: cadastrar/verificar o domínio `sfacco.com.br` (SPF + DKIM) e gerar a API key.

2. **Rotas públicas** (dentro do grupo throttle `auth`):
   - `POST /auth/forgot-password` `{email}` → resposta **sempre** `{"message": "Se o e-mail estiver cadastrado, você receberá o link em instantes."}`; se existir usuário, `Password::sendResetLink()` (broker padrão: hash SHA-256 no banco, expira 60 min, uso único);
   - `POST /auth/reset-password` `{token, email, password, password_confirmation}` → `Password::reset()`; sucesso: revoga **todos** os tokens do usuário, cria token novo e devolve `{user, access_token}` (login imediato);
   - Throttle dedicado para o forgot (3/min por IP) — evita usar a API para bombardear caixas de entrada alheias.

3. **Notification custom em PT-BR** — `App\Notifications\ResetPassword`: monta o link `FRONTEND_URL + '/redefinir-senha?token=…&email=…'` (nova env `FRONTEND_URL`), template de e-mail simples (logo + botão "Redefinir senha" + aviso de expiração em 60 min), e `lang/pt_BR` para as mensagens do broker (respostas de erro do reset em PT-BR, não "passwords.token").

4. **Config** — `config/auth.php` já traz o broker padrão (`users`, `expire: 60`); confirmar e ajustar expire se quiser outro valor.

5. **Testes** (`Mail::fake`): e-mail enviado para usuário existente; resposta idêntica para e-mail inexistente; reset com token inválido/expirado falha; reset revoga tokens antigos e devolve sessão nova.

### Fase 3 — Backend: remoção do Google + ajustes de documentação
**Estimativa: ~30 min**

1. Remover `Route::post('/auth/google', …)` e o método `AuthController::googleAuth` (e o `use Http`).
2. Atualizar `AuthTest.php` (excluir os 2 testes do Google) e o `rotas.md`.
3. **Plano de migração para contas Google existentes**: quem criou conta via Google tem e-mail real verificado → usa o novo "esqueci minha senha" uma vez e define senha. Zero lockout permanente.

### Fase 4 — Front: telas, fluxos e limpeza
**Estimativa: ~4–5 h · Seguindo o design system já existente (`DESIGN_SYSTEM.md`)**

1. **Remover Google completamente** (decisão registrada):
   - Deletar `services/googleAuth.js` e `components/common/GoogleIcon.js`;
   - Remover `googleAuthApi` (`api/auth.js`), `loginWithGoogle` (`AuthContext.js`), botões/handlers/effects de `LoginScreen.js` e `RegisterScreen.js` (incluindo o caminho fake `google_dev_`);
   - Remover deps `expo-auth-session` e `expo-web-browser` do `package.json`.

2. **Novas funções de API** (`api/auth.js`): `forgotPasswordApi`, `resetPasswordApi` (salva token/user — login automático), `changePasswordApi`.

3. **Telas novas** (mesmo layout do Login/Register: logo, hero, card, erros inline):
   - `ForgotPasswordScreen` — e-mail → estado de sucesso genérico (ícone de check + "Se o e-mail estiver cadastrado, o link foi enviado") + botão voltar ao login;
   - `ResetPasswordScreen` — lê `token`/`email` da URL, campos nova senha + confirmação com **medidor de força** (comprimento + variedade de caracteres → fraca/média/forte), botão "Redefinir senha", sucesso → usuário logado e volta ao app.

4. **Navegação/URL**:
   - `App.js`: `authScreen` ganha `'forgot' | 'reset'`; no boot (web), parse de `/redefinir-senha` com `?token=&email=` — **mesmo padrão já usado para `/gestao`** em `utils/linking.js` (novo `isResetRoute` + `getResetParams`);
   - `LoginScreen`: "Esqueceu?" deixa de ser `Alert` e navega para `ForgotPasswordScreen`.

5. **AuthContext endurecido**:
   - `client.js` emite evento `auth:unauthorized` no 401 → `AuthContext` assina e faz `setUser(null)/setToken(null)` (UI reage na hora);
   - `getMeApi`: remove o fallback stale — em 401 desloga; em falha de rede mantém sessão local (offline razoável).

6. **Acessibilidade/polish das telas auth**: `autoComplete` (`email`, `current-password`, `new-password`), `textContentType` no nativo, mensagens de erro 429 mapeadas ("Muitas tentativas. Aguarde e tente novamente.").

7. **ProfileScreen — seção "Conta"**:
   - "Alterar senha" (modal: senha atual + nova + confirmação → `PUT /auth/password`);
   - "Sair de todos os dispositivos" (→ `POST /auth/logout-all` + limpeza local).

### Fase 5 — Railway: configuração de produção
**Estimativa: ~1 h**

1. **Service `back`** — variáveis novas/alteradas:
   | Var | Valor |
   |---|---|
   | `CACHE_STORE` | `database` ⚠️ (hoje deve estar `array` — lockout/rate-limit não funcionam sem isso) |
   | `MAIL_MAILER` | `resend` |
   | `RESEND_API_KEY` | (do painel Resend) |
   | `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | `no-reply@sfacco.com.br` / `SportsF` |
   | `FRONTEND_URL` | URL pública do front (ex.: `https://sportsf.up.railway.app` ou domínio) |
   | `SANCTUM_TOKEN_EXPIRATION_MINUTES` | `43200` (30 dias) |
   | `CORS_ALLOWED_ORIGINS` | URLs do front (Railway + `sfacco.com.br` + `recanto.sfacco.com.br` + `localhost:8081` p/ dev) |
   | `APP_ENV` / `APP_DEBUG` | `production` / `false` (confirmar) |
2. **Service `front`**: a URL da API é **embutida em build-time** — após as mudanças, rodar `pnpm run build` com `.env.production` e redeployar o `dist`.
3. **Resend**: domínio verificado (SPF/DKIM), API key criada.
4. **Smoke test em produção**: cadastro → login → logout → esqueci → e-mail recebido → reset → senha antiga rejeitada → dispositivos antigos deslogados.

### Fase 6 — Testes e validação final
**Estimativa: ~1,5 h**

1. **Backend** (`php artisan test`): suite auth completa — cadastro, login, lockout, throttle, forgot/reset (happy + inválido + expirado), troca de senha, logout-all, expiração de token, CORS preflight.
2. **Checklist manual front** (web): fluxo completo ponta a ponta, estados de erro (429, link expirado, senhas diferentes), medidor de força, password manager reconhecendo os campos.
3. **Deploy e verificação** nas duas services.

---

## 4. Resumo de Prazo e Ordem

| Fase | Conteúdo | Estimativa | Depende de |
|---|---|---|---|
| 1 | Correções críticas back (proxy, throttle, lockout, expiração, CORS, troca de senha) | 2–3 h | — |
| 2 | Recuperação de senha back (Resend, broker, rotas, e-mail PT-BR) | 3–4 h | Fase 1 |
| 3 | Remoção Google back + docs | 30 min | — |
| 4 | Front (limpeza Google, telas forgot/reset, perfil, hardening) | 4–5 h | Fases 2–3 |
| 5 | Config Railway (envs, Resend, rebuild dist) | 1 h | Fases 1–4 |
| 6 | Testes + smoke em produção | 1,5 h | Fase 5 |
| **Total** | | **~12–15 h (2 dias úteis)** | |

**Por que nessa ordem:** o fluxo público de recuperação de senha só é seguro para expor depois que (a) o rate limit enxerga IP real, (b) o cache persiste entre requests e (c) existe lockout por conta — tudo isso é a Fase 1. O front vem depois porque consome as rotas prontas.

## 5. Fora do escopo (decisões conscientes)

- **2FA** — desnecessário para app de reservas neste estágio.
- **Verificação de e-mail no cadastro** — a recuperação por e-mail já prova posse quando importa.
- **OAuth social** — removido por decisão; quando voltar, implementar com verificação de `aud` + `email_verified` no backend.
- **Cookie httpOnly / SPA Sanctum** — complexidade cross-domain na Railway não compensa; Bearer + SecureStore é suficiente.
- **Rate limit "remember me" / refresh token rotation** — o app não pediu; expiração de 30 dias + logout-all cobre o caso de uso atual.