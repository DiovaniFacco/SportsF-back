# 📜 Regras de Negócio do Sistema de Agendamento de Quadras

Este documento consolida as regras de negócio, permissões e fluxos operacionais do aplicativo de agendamento de espaços e quadras esportivas.

---

## 1. 👥 Níveis de Usuário e Permissões

### **Perfis do Sistema**
1. **Administrador (`ADMIN`):**
   * Possui acesso total ao sistema.
   * Responsável por gerenciar os usuários e **criar/cadastrar novos Espaços Esportivos** no sistema.
   * Pode intervir, editar ou remover qualquer espaço, quadra ou agendamento em caso de necessidade.

2. **Gestor de Espaço (`GESTOR_ESPACO`):**
   * Responsável por administrar o espaço atribuído a ele pelo Administrador.
   * Pode modificar os dados do espaço e adicionar/gerenciar as **Quadras**.
   * Define os horários de funcionamento, duração dos slots, itens configuráveis e preços das quadras.
   * Pode realizar agendamentos manuais (presenciais/WhatsApp) e efetuar bloqueios na agenda.

3. **Usuário Comum / Cliente (`CLIENTE`):**
   * Pode visualizar a lista de espaços esportivos, quadras disponíveis, horários e preços.
   * Pode realizar agendamentos, efetuar pagamentos e gerenciar/cancelar suas próprias reservas.
   * Pode avaliar espaços e quadras em agendamentos concluídos.

---

## 2. 🏟️ Gestão de Espaços e Quadras

- **Criação de Espaço:** Um espaço esportivo é inicialmente cadastrado por um **Administrador** no sistema e vinculado a um **Gestor de Espaço**.
- **Configuração pelo Gestor:** Após receber o espaço, o Gestor pode:
  - Adicionar e editar **Quadras** (ex: Quadra Society 1, Quadra de Tênis A, Quadra de Sand Volleyball).
  - Definir **horários de funcionamento** para cada quadra por dia da semana.
  - Definir os **preços por hora/slot**.
  - Configurar **itens e serviços adicionais** (ex: iluminação noturna, aluguel de coletes, bolas, equipamento).
- **Visualização Pública:** Qualquer usuário comum autenticado ou visitante pode consultar os espaços, fotos, endereços, quadras e horários disponíveis.

---

## 3. 📅 Regras de Agendamento e Concorrência

- **Bloqueio Anti-Double Booking (Concorrência):** O sistema impede rigorosamente que o mesmo horário em uma quadra seja reservado por dois clientes simultaneamente (com trava pessimista no banco de dados).
- **Antecedência Mínima:** O cliente só pode agendar com pelo menos **1 hora deantecedência** (evita agendamento em cima da hora sem aviso ao gestor). (isso aqui  o tempo minimo pode ser configurado pelo gerenciador)
- **Antecedência Máxima:** O cliente só pode agendar com no máximo **30 dias de antecedência**. (isso aqui  o tempo maximo pode ser configurado pelo gerenciador)
- **Duração Padronizada:** As reservas devem respeitar os slots configurados pela quadra (ex: blocos fixos de 60 min ou 90 min) para evitar buracos na grade.
- **Limite de Reservas Ativas:** Cada cliente pode ter no máximo **X reservas pendentes/futuras** ao mesmo tempo para evitar spam ou uso malicioso. (isso aqui  o numero maximo de reservas pode ser configurado pelo gerenciador)

---

- atualmente nao havera pagamento pelo app
---

## 6. 💲 Regras de Tarifação Flexível (Preço Dinâmico)

- **Horário Nobre (Peak Hours):** O gestor pode definir tabelas de preço diferenciadas por dia e horário (ex: Segunda a Sexta das 18h às 23h e Finais de Semana com valor superior aos horários diurnos).
- **Feriados e Datas Especiais:** Possibilidade de aplicar valores diferenciados em datas comemorativas.
- **Adicionais Opcionais:** Taxas extras configuráveis no momento da reserva (ex: taxa de iluminação noturna R$ 20, aluguel de bola R$ 15).

---

## 7. 🛠️ Operação do Gestor (Bloqueios e Agendamento Balcão)

- **Bloqueio de Agenda (Manutenção/Eventos):** O gestor pode fechar quadras ou horários específicos para manutenção, reforma, chuva ou campeonatos internos.
- **Agendamento Presencial / WhatsApp:** O gestor pode registrar agendamentos manuais diretamente no painel administrativo para clientes que entrarem em contato por telefone ou WhatsApp, ocupando a vaga na agenda.
- **Horário Fixo (Aula / Mensalista):** O gestor pode fixar um horário semanal recorrente (ex. toda segunda 19:00–20:00 do "Time do João"). O horário aparece sempre ocupado para clientes e gestores; reservas avulsas no mesmo horário são rejeitadas. O gestor pode **desmarcar um único dia** (exceção — a série continua) ou **excluir a série toda**. A série deve estar dentro da grade de funcionamento e alinhada aos slots da quadra; não pode sobrepor outra série fixa nem colidir com reservas avulsas já confirmadas nas próximas ocorrências.

---

## 8. ⭐ Regras de Avaliação e Reputação

- **Avaliação Verificada:** Apenas clientes que possuem um agendamento com status `CONCLUÍDO` podem enviar nota (1 a 5 estrelas) e comentário para o espaço esportivo.
