**Objetivo:**
Atuar como um Arquiteto de Software e Desenvolvedor Senior especializado em PHP e PostgreSQL para planejar e estruturar a arquitetura inicial de uma **API RESTful de Agendamento de Quadras Esportivas**.
---


### 1. Visão Geral do Domínio


O sistema gerencia espaços esportivos, suas quadras e o fluxo de reservas/horários disponíveis.
**Entidades e Regras de Negócio Principais:**
1. **Espaço (Venue/Complexo):** Cadastro do local físico (Nome, Endereço, Regras do Local, Proprietário/Manager).
2. **Quadras (Courts):** Múltiplas quadras pertencentes a um Espaço (ex: Quadra Society 1, Quadra de Tênis A, Areia 1) com atributos como modalidade, tipo de piso e valor/hora.
3. **Grade de Horários & Disponibilidade:** Configuração flexível de dias e horários de funcionamento por quadra (ex: dias da semana, duração do slot de tempo, intervalos/manutenção).
4. **Agendamento (Bookings):** Processo de reserva de um slot por um cliente. **Regra crítica:** Trava de concorrência no banco de dados para impedir *double-booking* (dois clientes reservando a mesma quadra no mesmo horário).
5. **Gestão de Usuários & Acessos:** Perfis distintos (`ADMIN`, `GESTOR_ESPACO`, `CLIENTE`) com restrições por endpoint (RBAC).


---


### 2. Stack Tecnológica & Boas Práticas


* **Linguagem:** PHP 8.3+ (seguindo PSR-12, Tipagem Forte, Strict Types).
* **Framework sugerido:** php puro, Laravel (ou Symfony/Slim, justificar a escolha com foco em rapidez de desenvolvimento e ecossistema de testes/migrations).
* **Banco de Dados:** PostgreSQL (uso de restrições nativas, tipos `TSTZRANGE` ou chaves compostas para prevenção de sobreposição de horários, e migrations).
* **Ambiente de Desenvolvimento:** Docker e Docker Compose (`php-fpm`, `nginx`, `postgres`, `pgadmin` ou `mailpit`) para subir o ambiente localmente com um único comando.
* **Arquitetura:** Clean Architecture / DDD simplificado ou Layered Architecture (Controllers -> Services -> Repositories -> Entities), visando facilidade de testes automatizados (Pest / PHPUnit).
* **Autenticação:** JWT (JSON Web Tokens) 


---


### 3. O que preciso no Plano de Implementação (Fase 1 - Apenas a API):

1. **Configuração da Estrutura Inicial do Projeto:** Setup do ambiente Docker Compose e estrutura de diretórios recomendada.
2. **Tratamento de Regras Críticas:** Como estruturar o código e as queries SQL para gerenciar sobreposição de horários e concorrência na reserva.
3. **Roteiro de Execução (Roadmap de Desenvolvimento):** Fases ordenadas do que construir primeiro para ter um MVP funcional e testável o mais rápido possível.

Por favor, elabore um plano passo a passo cobrindo:
4. **Modelagem do Banco de Dados (ERD / Relacionamentos):** Estrutura detalhada de tabelas no PostgreSQL com chaves primárias, estrangeiras e índices recomendados para buscas de disponibilidade por data/hora.
5. **Desenho da API REST (Endpoints & Métodos):** Lista de rotas essenciais para:
* Autenticação e Gestão de Usuários
* CRUD de Espaços e Quadras
* Configuração de Horários/Disponibilidade
* Consulta de Slots Livres e Criação/Cancelamento de Reservas


