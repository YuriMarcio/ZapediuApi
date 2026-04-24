# Contexto do Sistema: Zapediu? (Backend)

## 1. Divisão de Responsabilidades
O projeto é dividido em dois grandes domínios. Você deve respeitar essa separação:

- **API Bot (WhatsApp):** Rotas e lógica focadas no cliente final. O objetivo é converter conversa em pedido.
- **Web Dashboard:** Rotas e lógica administrativas. Gestão de estoque, status de pedidos e relatórios.

## 2. Padrões de Código e Organização
- **Arquitetura:** Clean Architecture Lite (Routes -> Controllers -> Services -> Providers).
- **DRY (Don't Repeat Yourself):** Proibido duplicar funções. Lógica de cálculo de frete, por exemplo, deve estar em um `Service` reutilizável, acessível tanto pelo Bot quanto pelo Dashboard.
- **Providers:** Integrações externas (Z-API, Gateways de Pagamento) devem ser isoladas em `src/providers`.

## 3. Regras para o Bot (API)
- **Estado do Chat:** O bot deve sempre consultar o estado atual do pedido antes de responder.
- **Fluxo:** Identificação -> Cardápio -> Carrinho -> Endereço -> Pagamento -> Confirmação.
- **Z-API:** Use estritamente o padrão de botões e carrosséis para melhorar a UX no WhatsApp.

## 4. Regras para o Dashboard (Web)
- **Segurança:** Todas as rotas `web/` exigem validação de token administrativo.
- **Performance:** Consultas de relatórios devem ser otimizadas.

## 5. Instruções de Geração (Para o LLM)
Ao criar novas funcionalidades:
1. Verifique se já existe um `Service` ou `Util` que resolva parte do problema.
2. Se for criar uma função genérica, coloque-a em `src/utils`.
3. Mantenha os arquivos de rotas limpos, apenas chamando os controllers.