# 📖 Documentação da API RESTful de Agendamento de Quadras

**Base URL:** `http://localhost:8000/api/v1`

---

## 📑 Sumário

1. [Healthcheck](#1-healthcheck)
2. [Autenticação & Usuários](#2-autenticação--usuários)
3. [Espaços Esportivos (Venues)](#3-espaços-esportivos-venues)
4. [Quadras (Courts)](#4-quadras-courts)
5. [Grade de Funcionamento & Slots Livres](#5-grade-de-funcionamento--slots-livres)
6. [Agendamentos (Bookings)](#6-agendamentos-bookings)
7. [Precificação Flexível (Horários Nobres & Datas Especiais)](#7-precificação-flexível-horários-nobres--datas-especiais)
8. [Bloqueios de Agenda (Manutenção / Eventos)](#8-bloqueios-de-agenda-manutenção--eventos)
9. [Horários Fixos (Aulas / Mensalistas)](#9-horários-fixos-aulas--mensalistas)
10. [Métricas de Acesso (Analytics)](#10-métricas-de-acesso-analytics)

---

## 1. Healthcheck

### `GET /api/v1/health`
Verifica o status operacional da API, versão do PHP e conexão com o banco de dados.

- **Autenticação:** Pública
- **Payload:** Nenhum

**Exemplo de Resposta (`200 OK`):**
```json
{
  "status": "ok",
  "timestamp": "2026-08-08T21:02:59+00:00",
  "services": {
    "database": "connected",
    "php_version": "8.3.33"
  }
}
```

---

## 2. Autenticação & Usuários

### `POST /api/v1/auth/register`
Cadastra um novo usuário no sistema, **sempre com o perfil `CLIENTE`**
(o campo `role`, se enviado, é ignorado por segurança).

Para tornar alguém gestor de um complexo, um `ADMIN` deve vincular o
usuário como dono via `POST /api/v1/venues` (`user_id` ou `new_manager`) —
o dono é promovido automaticamente a `GESTOR_ESPACO`.

- **Autenticação:** Pública (throttle `auth`: 10/min por IP + 60/dia por conta)
- **Payload:**
```json
{
  "name": "João Cliente",
  "email": "joao@cliente.com",
  "password": "password123",
  "password_confirmation": "password123",
  "phone": "11999999999"
}
```

**Exemplo de Resposta (`201 Created`):**
```json
{
  "message": "Usuário registrado com sucesso",
  "user": {
    "id": 1,
    "name": "João Gestor",
    "email": "joao@arena.com",
    "role": "GESTOR_ESPACO",
    "phone": "11999999999",
    "created_at": "2026-08-08T18:00:00.000000Z",
    "updated_at": "2026-08-08T18:00:00.000000Z"
  },
  "access_token": "1|qX8zY9...",
  "token_type": "Bearer"
}
```

---

### `POST /api/v1/auth/login`
Autentica o usuário e gera o Token Bearer (válido por 30 dias).
Após 5 falhas por conta há lockout de 5 min (`429`).

- **Autenticação:** Pública (throttle `auth`)
- **Payload:**
```json
{
  "email": "joao@arena.com",
  "password": "password123"
}
```

**Exemplo de Resposta (`200 OK`):**
```json
{
  "message": "Login realizado com sucesso",
  "user": {
    "id": 1,
    "name": "João Gestor",
    "email": "joao@arena.com",
    "role": "GESTOR_ESPACO"
  },
  "access_token": "2|aB9cX0...",
  "token_type": "Bearer"
}
```

---

### `POST /api/v1/auth/forgot-password`
Solicita o link de recuperação. Resposta **sempre idêntica** (não revela
se o e-mail existe). O e-mail contém o link
`{FRONTEND_URL}/redefinir-senha?token=…&email=…` (expira em 60 min, uso único).

- **Autenticação:** Pública (throttle `forgot`: 3/min por IP)
- **Payload:** `{ "email": "joao@arena.com" }`
- **Resposta (`200 OK`):** `{ "message": "Se o e-mail estiver cadastrado, você receberá o link em instantes." }`

---

### `POST /api/v1/auth/reset-password`
Redefine a senha com o token do e-mail. Revoga **todas** as sessões antigas
e já devolve uma sessão nova (login automático).

- **Autenticação:** Pública (throttle `auth`)
- **Payload:**
```json
{
  "token": "…",
  "email": "joao@arena.com",
  "password": "novasenha123",
  "password_confirmation": "novasenha123"
}
```

**Exemplo de Resposta (`200 OK`):**
```json
{
  "message": "Senha redefinida com sucesso.",
  "user": { "id": 1, "name": "João", "email": "joao@arena.com", "role": "CLIENTE" },
  "access_token": "3|xYz…",
  "token_type": "Bearer"
}
```

---

### `PUT /api/v1/auth/password`
Altera a senha do usuário logado. Mantém o token atual, revoga os demais.

- **Autenticação:** Requer Token Bearer
- **Payload:**
```json
{
  "current_password": "atual1234",
  "password": "novasenha456",
  "password_confirmation": "novasenha456"
}
```

---

### `POST /api/v1/auth/logout-all`
Encerra **todas** as sessões do usuário (todos os dispositivos).

- **Autenticação:** Requer Token Bearer
- **Payload:** Nenhum

---

### `GET /api/v1/auth/me`
Retorna os dados do usuário autenticado no momento.

- **Autenticação:** Requer Token Bearer (`Authorization: Bearer <token>`)
- **Payload:** Nenhum

**Exemplo de Resposta (`200 OK`):**
```json
{
  "user": {
    "id": 1,
    "name": "João Gestor",
    "email": "joao@arena.com",
    "role": "GESTOR_ESPACO",
    "phone": "11999999999"
  }
}
```

---

### `POST /api/v1/auth/logout`
Revoga o token atual do usuário.

- **Autenticação:** Requer Token Bearer (`Authorization: Bearer <token>`)
- **Payload:** Nenhum

**Exemplo de Resposta (`200 OK`):**
```json
{
  "message": "Logout realizado com sucesso"
}
```

---

## 3. Espaços Esportivos (Venues)

### `GET /api/v1/venues`
Lista todos os espaços esportivos cadastrados.

- **Autenticação:** Pública
- **Query Params (opcional):** `?city=São Paulo`

**Exemplo de Resposta (`200 OK`):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "name": "Arena Central Esportes",
      "description": "Complexo esportivo completo com vestiários.",
      "address": "Rua dos Esportes, 100",
      "city": "São Paulo",
      "state": "SP",
      "phone": "11988888888",
      "courts": []
    }
  ]
}
```

---

### `POST /api/v1/venues`
Cadastra um novo espaço esportivo.

- **Autenticação:** Requer Token Bearer (`GESTOR_ESPACO` ou `ADMIN`)
- **Payload:**
```json
{
  "name": "Arena Central Esportes",
  "description": "Complexo esportivo completo com vestiários e lanchonete.",
  "address": "Rua dos Esportes, 100",
  "city": "São Paulo",
  "state": "SP",
  "phone": "11988888888"
}
```

**Exemplo de Resposta (`201 Created`):**
```json
{
  "message": "Espaço criado com sucesso",
  "venue": {
    "id": 1,
    "user_id": 1,
    "name": "Arena Central Esportes",
    "description": "Complexo esportivo completo com vestiários e lanchonete.",
    "address": "Rua dos Esportes, 100",
    "city": "São Paulo",
    "state": "SP",
    "phone": "11988888888",
    "courts": []
  }
}
```

---

### `GET /api/v1/venues/{id}`
Exibe os detalhes de um espaço e suas quadras.

- **Autenticação:** Pública

**Exemplo de Resposta (`200 OK`):**
```json
{
  "venue": {
    "id": 1,
    "name": "Arena Central Esportes",
    "city": "São Paulo",
    "courts": [
      {
        "id": 1,
        "name": "Quadra Society 1",
        "type": "society",
        "hourly_rate": "150.00"
      }
    ]
  }
}
```

---

### `PUT /api/v1/venues/{id}`
Atualiza os dados de um espaço.

- **Autenticação:** Requer Token Bearer (Proprietário ou Admin)
- **Payload:**
```json
{
  "name": "Arena Central Esportes Premium",
  "phone": "11977777777"
}
```

---

### `DELETE /api/v1/venues/{id}`
Remove um espaço e todas as suas quadras vinculadas.

- **Autenticação:** Requer Token Bearer (Proprietário ou Admin)

---

## 4. Quadras (Courts)

### `GET /api/v1/venues/{venue_id}/courts`
Lista todas as quadras de um espaço específico.

- **Autenticação:** Pública

---

### `POST /api/v1/venues/{venue_id}/courts`
Cadastra uma nova quadra em um espaço.

- **Autenticação:** Requer Token Bearer (Proprietário do espaço ou Admin)
- **Payload:**
```json
{
  "name": "Quadra Society 1",
  "type": "society",
  "hourly_rate": 150.00
}
```

**Exemplo de Resposta (`201 Created`):**
```json
{
  "message": "Quadra criada com sucesso",
  "court": {
    "id": 1,
    "venue_id": 1,
    "name": "Quadra Society 1",
    "type": "society",
    "hourly_rate": "150.00"
  }
}
```

---

### `PUT /api/v1/courts/{id}`
Atualiza os dados de uma quadra.

- **Autenticação:** Requer Token Bearer (Proprietário ou Admin)

---

### `DELETE /api/v1/courts/{id}`
Remove uma quadra.

- **Autenticação:** Requer Token Bearer (Proprietário ou Admin)

---

## 5. Grade de Funcionamento & Slots Livres

### `GET /api/v1/courts/{court_id}/operating-hours`
Consulta os horários de funcionamento cadastrados para a quadra.

- **Autenticação:** Pública

---

### `POST /api/v1/courts/{court_id}/operating-hours`
Configura ou atualiza o horário de funcionamento da quadra para um dia da semana.
Turnos são sempre **no mesmo dia**: `end_time` deve ser após `start_time`
(virada de meia-noite não é suportada) e ambos no formato `HH:MM`.

- **Autenticação:** Requer Token Bearer (Proprietário ou Admin)
- **Payload:**
```json
{
  "day_of_week": 1,
  "start_time": "08:00",
  "end_time": "22:00",
  "slot_duration_minutes": 60
}
```
*(Nota: `day_of_week`: 0 = Domingo, 1 = Segunda-feira, ..., 6 = Sábado)*

---

### `GET /api/v1/courts/{court_id}/available-slots?date=YYYY-MM-DD`
Calcula e retorna todos os slots de tempo do dia, indicando se cada horário está livre (`available: true`) ou ocupado (`available: false`).

- **Autenticação:** Pública
- **Query Params:** `?date=2026-08-10` (opcional: `&include_past=1` para incluir slots já passados)
- **Resposta:** inclui `operating` (existe grade no dia), `is_past` (data anterior a hoje)
  e `message` (ex.: aviso de data passada ou de bloqueio indeterminado).

**Exemplo de Resposta (`200 OK`):**
```json
{
  "court_id": 1,
  "court_name": "Quadra Society 1",
  "date": "2026-08-10",
  "day_of_week": 1,
  "operating": true,
  "slots": [
    {
      "start_time": "2026-08-10 08:00:00",
      "end_time": "2026-08-10 09:00:00",
      "formatted_time": "08:00 - 09:00",
      "price": 150,
      "available": true
    },
    {
      "start_time": "2026-08-10 09:00:00",
      "end_time": "2026-08-10 10:00:00",
      "formatted_time": "09:00 - 10:00",
      "price": 150,
      "available": false
    }
  ]
}
```

---

## 6. Agendamentos (Bookings)

### `GET /api/v1/bookings`
Lista os agendamentos.
- Se for **CLIENTE**: exibe os agendamentos feitos por ele.
- Se for **GESTOR_ESPACO**: exibe os agendamentos das quadras de seus espaços.

- **Autenticação:** Requer Token Bearer

---

### `POST /api/v1/bookings`
Realiza a reserva de um slot de tempo em uma quadra. Possui **trava de concorrência pessimista (anti-double booking)** no banco de dados.

- **Autenticação:** Requer Token Bearer
- **Payload:**
```json
{
  "court_id": 1,
  "start_time": "2026-08-10 14:00:00",
  "end_time": "2026-08-10 15:00:00"
}
```

**Exemplo de Resposta Sucesso (`201 Created`):**
```json
{
  "message": "Agendamento realizado com sucesso!",
  "booking": {
    "id": 1,
    "court_id": 1,
    "user_id": 2,
    "start_time": "2026-08-10T14:00:00.000000Z",
    "end_time": "2026-08-10T15:00:00.000000Z",
    "total_price": "150.00",
    "status": "confirmed",
    "created_at": "2026-08-08T18:00:00.000000Z"
  }
}
```

**Exemplo de Resposta Erro - Concorrência/Double Booking (`422 Unprocessable Content`):**
```json
{
  "message": "Este horário já se encontra reservado por outro cliente (Double booking bloqueado).",
  "errors": {
    "start_time": [
      "Este horário já se encontra reservado por outro cliente (Double booking bloqueado)."
    ]
  }
}
```

---

### `POST /api/v1/bookings/batch`
Realiza a reserva de **vários slots de uma vez, de forma atômica (tudo ou nada)**.
Usado pela seleção multi-slot do app: se qualquer horário falhar
(conflito, limite de reservas, fora da grade), **nenhuma** reserva é criada.

- **Autenticação:** Requer Token Bearer
- **Payload:**
```json
{
  "court_id": 1,
  "notes": "Observação opcional",
  "slots": [
    { "start_time": "2026-08-10 14:00:00", "end_time": "2026-08-10 15:00:00" },
    { "start_time": "2026-08-10 15:00:00", "end_time": "2026-08-10 16:00:00" }
  ]
}
```

**Exemplo de Resposta Sucesso (`201 Created`):**
```json
{
  "message": "2 agendamentos realizados com sucesso!",
  "bookings": [ { "id": 1, "status": "confirmed" }, { "id": 2, "status": "confirmed" } ],
  "booking": { "id": 1, "status": "confirmed" }
}
```

---

### `GET /api/v1/bookings/{id}`
Exibe os detalhes de uma reserva específica.

- **Autenticação:** Requer Token Bearer

---

### `PATCH /api/v1/bookings/{id}/cancel`
Cancela um agendamento confirmado.

- **Autenticação:** Requer Token Bearer (Cliente dono do agendamento, Gestor da quadra ou Admin)

**Exemplo de Resposta (`200 OK`):**
```json
{
  "message": "Agendamento cancelado com sucesso.",
  "booking": {
    "id": 1,
    "status": "cancelled"
  }
}
```

---

## 7. Precificação Flexível (Horários Nobres & Datas Especiais)

### `GET /api/v1/courts/{court_id}/pricing-rules`
Lista as regras de horário nobre cadastradas para a quadra.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)

### `POST /api/v1/courts/{court_id}/pricing-rules`
Cadastra uma regra de preço por horário nobre.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)
- **Payload:**
```json
{
  "day_of_week": 1,
  "start_time": "18:00",
  "end_time": "22:00",
  "hourly_rate": 180.00,
  "label": "Horário Nobre Noturno"
}
```

### `DELETE /api/v1/pricing-rules/{id}`
Remove uma regra de horário nobre.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)

### `GET /api/v1/courts/{court_id}/special-prices`
Lista as tabelas de preços em feriados e datas especiais.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)

### `POST /api/v1/courts/{court_id}/special-prices`
Cadastra preço diferenciado para uma data específica (feriado).
`event_date` usa o formato `YYYY-MM-DD` e é devolvida pela API
nesse mesmo formato (conceito de data, sem hora/timezone).
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)
- **Payload:**
```json
{
  "event_date": "2026-12-25",
  "hourly_rate": 250.00,
  "label": "Feriado de Natal"
}
```

### `DELETE /api/v1/special-prices/{id}`
Remove um preço especial de feriado.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)

---

## 8. Bloqueios de Agenda (Manutenção / Eventos)

### `GET /api/v1/courts/{court_id}/blockouts`
Lista os bloqueios de agenda da quadra.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)

### `POST /api/v1/courts/{court_id}/blockouts`
Bloqueia um período da agenda por manutenção, torneio ou chuva.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)
- **Payload:**
```json
{
  "start_time": "2026-08-15 14:00:00",
  "end_time": "2026-08-15 18:00:00",
  "reason": "Troca da grama sintética / Manutenção"
}
```

### `DELETE /api/v1/blockouts/{id}`
Remove um bloqueio de agenda e libera os horários.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)

---

## 9. Horários Fixos (Aulas / Mensalistas)

Recorrência semanal **virtual** (sem materializar reservas): ex. toda
segunda 19:00–20:00 do "Time do João". O slot aparece como ocupado
(`available: false`, `is_fixed: true`) em `available-slots` e o `POST
/bookings` rejeita conflito com mensagem amigável. Desmarcar **um dia**
cria uma exceção (a série continua); excluir remove a série toda.

- `day_of_week`: 0=domingo … 6=sábado (igual `operating_hours`)
- `start_time`/`end_time`: `HH:mm`, mesmo dia, alinhados à grade da quadra
- `valid_from` obrigatório (`YYYY-MM-DD`, hoje ou futuro); `valid_until` opcional (null = sem fim)

### `GET /api/v1/courts/{court_id}/fixed-schedules`
Lista as séries fixas da quadra (com `exceptions`).
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)

### `POST /api/v1/courts/{court_id}/fixed-schedules`
Cria uma série fixa semanal.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)
- **Payload:**
```json
{
  "day_of_week": 1,
  "start_time": "19:00",
  "end_time": "20:00",
  "customer_name": "Time do João",
  "customer_phone": "11999990000",
  "valid_from": "2026-09-29",
  "valid_until": null,
  "notes": "mensalista"
}
```

### `PUT /api/v1/fixed-schedules/{id}`
Atualiza a série (dia, horário, cliente, vigência, `is_active`).
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)

### `DELETE /api/v1/fixed-schedules/{id}`
Exclui a série toda e libera as ocorrências futuras.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)

### `POST /api/v1/fixed-schedules/{id}/exceptions`
Desmarca **só um dia** (ex: feriado). A série continua nos demais dias.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)
- **Payload:**
```json
{
  "date": "2026-10-05",
  "reason": "Feriado / folga"
}
```

### `DELETE /api/v1/fixed-schedules/{id}/exceptions/{exceptionId}`
Reativa um dia desmarcado (o fixo volta a valer na data).
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)

### `GET /api/v1/fixed-schedules/{id}/occurrences?from=YYYY-MM-DD&to=YYYY-MM-DD`
Expande as próximas ocorrências da série (máx. 92 dias), indicando `skipped`.
- **Autenticação:** Requer Token Bearer (Gestor ou Admin)

### Lançar o dia (pagamento da ocorrência)
Para marcar pago/pendente/não pago de **um dia** do fixo, o gestor cria um
agendamento normal vinculado à série — ele entra em Agendamentos, Métricas e
Faturamento como qualquer reserva, sem remover a série:

### `POST /api/v1/bookings` com `fixed_schedule_id`
- **Autenticação:** Requer Token Bearer (Gestor ou Admin, via `is_manual: true`)
- O vínculo só é aceito se a data/horário corresponder a uma ocorrência válida
  (dentro da vigência, no dia da semana, sem exceção). Cliente não consegue
  usar o vínculo para furar o fixo.
- **Payload:**
```json
{
  "court_id": 1,
  "start_time": "2026-10-07 19:00:00",
  "end_time": "2026-10-07 20:00:00",
  "is_manual": true,
  "customer_name": "Time do João",
  "paid_amount": 40,
  "fixed_schedule_id": 3
}
```
- A resposta inclui `booking.court_fixed_schedule_id` e `booking.fixed_schedule`;
  `GET /bookings` também expõe o vínculo.


---

## 10. Métricas de Acesso (Analytics)

Coleta e agregação de tráfego/audiência do app. O objetivo principal é medir
**quantos acessam sem estar logados (anônimos) vs logados**, quais telas são
mais vistas e por qual plataforma (web/Android/iOS).

- A tabela `access_events` guarda cada abertura do app (`app_open`) e cada
  visualização de tela (`page_view`).
- `user_id` **NULL** = visitante não logado; preenchido = usuário logado
  (com `user_role` do momento do acesso).
- `visitor_id` = identificador anônimo persistido no dispositivo.
- `session_id` = agrupa as telas de uma mesma sessão (abertura do app).
- `ip` = IP completo.

---

### `POST /api/v1/analytics/track`
Registra um evento de acesso. **Endpoint público** — o token Bearer é
**opcional**: quando presente e válido, o acesso é atribuído ao usuário
logado; sem token, é contabilizado como anônimo.

- **Autenticação:** Pública (throttle `analytics`: 60/min por IP)
- **Payload:**
```json
{
  "event_type": "page_view",
  "screen": "complexo",
  "visitor_id": "0f8c…",
  "session_id": "9a3b…",
  "venue_id": 1,
  "platform": "web",
  "referrer": "https://www.google.com/",
  "metadata": {}
}
```
*(`event_type`: `app_open` | `page_view`; `screen`: identificador da tela.)*

**Resposta (`201 Created`):** `{ "status": "ok" }`

---

### `GET /api/v1/analytics/overview`
Visão geral de acessos no período.
- **ADMIN:** todos os complexos (visão global).
- **GESTOR:** apenas os complexos que administra (`scope: "managed"`).

- **Autenticação:** Requer Token Bearer (`GESTOR_ESPACO` ou `ADMIN`)
- **Query Params:** `?period=today|week|month|30d` (padrão `today`)

**Exemplo de Resposta (`200 OK`):**
```json
{
  "period": "today",
  "scope": "global",
  "range": { "from": "2026-10-15T00:00:00-03:00", "to": "2026-10-15T23:59:59-03:00" },
  "totals": {
    "events": 128,
    "sessions": 42,
    "unique_visitors": 57,
    "anonymous_visitors": 50,
    "logged_visitors": 7,
    "anonymous_events": 110,
    "logged_events": 18,
    "anonymous_percentage": 88,
    "logged_percentage": 12
  },
  "screens": [ { "screen": "complexo", "views": 60 } ],
  "platforms": [ { "platform": "web", "events": 100 } ],
  "daily": [ { "date": "2026-10-15", "anonymous": 50, "logged": 7, "total": 57 } ],
  "venues": [ { "venue_id": 1, "name": "Arena Central", "anonymous": 30, "logged": 4, "visitors": 34, "events": 70 } ],
  "recent": [
    {
      "id": 3,
      "user_id": null,
      "user_name": null,
      "user_role": null,
      "anonymous": true,
      "venue_id": 1,
      "venue_name": "Arena Central",
      "screen": "complexo",
      "event_type": "page_view",
      "platform": "web",
      "ip": "203.0.113.10",
      "created_at": "2026-10-15T10:16:05-03:00"
    }
  ]
}
```

---

### `GET /api/v1/venues/{venue_id}/analytics`
Métricas de acesso de **um complexo específico** (mesma estrutura de
`/analytics/overview`, com `scope: "venue"`).

- **Autenticação:** Requer Token Bearer (Proprietário do complexo ou Admin)
- **Query Params:** `?period=today|week|month|30d`
