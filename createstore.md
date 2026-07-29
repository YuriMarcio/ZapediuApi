# Analisar e corrigir o fluxo de ativação das lojas

**Data da análise:** 29/07/2026

## Objetivo

Analisar o fluxo completo da loja, desde sua criação até a disponibilização no WhatsApp.

Antes de alterar o código, apresentar um diagnóstico com:

* Fluxo atual;
* Regras já implementadas;
* Problemas encontrados;
* Validações existentes no frontend;
* Validações existentes no backend;
* Campos atuais do banco;
* Alterações necessárias.

As regras devem controlar separadamente:

1. Operação da loja;
2. Integração com o Mercado Pago;
3. Visibilidade no WhatsApp;
4. Lojas mockadas para teste;
5. Acesso temporário do gerente ou vendedor;
6. Preenchimento e edição do cardápio.

---

## Fluxo esperado

```text
Loja criada
↓
Loja entra em onboarding
↓
Loja permanece oculta no WhatsApp
↓
Gerente ou vendedor recebe acesso temporário
↓
Possui 15 dias corridos para configurar a loja
↓
Cardápio e configurações são preenchidos
↓
Mercado Pago é conectado
↓
Backend valida a integração
↓
Loja é habilitada
↓
Loja aparece no WhatsApp
```

Lojas mockadas poderão ser exibidas sem Mercado Pago, desde que sejam liberadas manualmente pelo master.

---

## Estado inicial

Ao criar uma loja:

```text
Status: Em onboarding
Mercado Pago: Pendente
WhatsApp: Oculta
Loja de teste: Não
Acesso temporário: Ativo
Cardápio: Pendente
```

O prazo de 15 dias começa na data e hora da criação.

Copiar ou visualizar a credencial não pode renovar ou reiniciar esse prazo.

---

## Campos principais

### Operação da loja

```text
is_active
```

```text
true  = loja operacional
false = loja desativada
```

Quando `false`, bloquear:

* Exibição no WhatsApp;
* Produtos;
* Carrinho;
* Checkout;
* Novos pedidos;
* Links diretos;
* Busca, categorias, carrosséis e recomendações.

A desativação deve preservar produtos, pedidos, configurações, dados financeiros e histórico.

---

### Loja de teste

```text
is_test_store
```

```text
false = loja real
true  = loja mockada para testes
```

Regras:

* Padrão `false`;
* Somente o master pode alterar;
* Validação obrigatória no backend;
* Exibir selo **Loja de teste**;
* Registrar alteração em auditoria;
* Loja desativada nunca aparece;
* Loja de teste sem Mercado Pago não pode realizar cobrança real;
* Checkout deve simular ou bloquear pagamentos reais.

---

### Acesso temporário

```text
seller_access_enabled
```

```text
true  = gerente ou vendedor pode acessar e editar
false = acesso bloqueado
```

O acesso deve considerar:

```text
seller_access_enabled = true
E
(
    dentro dos 15 dias iniciais
    OU
    liberado manualmente pelo administrador
)
```

Caso necessário, criar:

```text
seller_access_manually_enabled
```

A liberação manual não deve alterar:

* Loja;
* Mercado Pago;
* WhatsApp;
* Data de criação;
* Prazo original de 15 dias.

---

## Visibilidade no WhatsApp

A loja poderá aparecer quando:

```text
is_active = true
E
(
    Mercado Pago conectado
    OU
    is_test_store = true
)
```

Caso o sistema exija cardápio válido:

```text
is_active = true
E
Loja não bloqueada
E
Possui pelo menos um produto ativo
E
(
    Mercado Pago conectado
    OU
    is_test_store = true
)
```

Essa validação deve ser aplicada no backend em:

* Listagem de lojas;
* Busca;
* Categorias;
* Recomendações;
* Carrosséis;
* Promoções;
* Links diretos;
* Produtos;
* Carrinho;
* Checkout;
* Retomada de pedidos;
* Mensagens antigas.

Ao acessar uma loja indisponível:

```text
Esta loja não está disponível no momento.

Escolha outra loja para continuar seu pedido.
```

Não permitir visualizar produtos, adicionar ao carrinho, criar pedido, finalizar checkout ou pagar.

---

## Mercado Pago

Lojas reais somente podem aparecer no WhatsApp após validação do backend.

Validar:

* Conta realmente conectada;
* Identificador salvo;
* Credenciais armazenadas;
* Integração válida;
* Dados necessários para split;
* Callback ou webhook processado.

Uma alteração visual no frontend não pode liberar a loja.

---

## Expiração do acesso

Após 15 dias:

* Bloquear login temporário;
* Bloquear produtos e categorias;
* Bloquear edição do cardápio;
* Bloquear rotas protegidas no backend;
* Manter a loja funcionando, caso já esteja operacional.

Mensagem:

```text
Seu período de acesso a esta loja foi encerrado.

Solicite a liberação ao administrador.
```

Desativar o acesso do gerente não pode retirar a loja do WhatsApp.

Desativar a loja deve retirar toda a operação do WhatsApp.

---

## Cardápio

### Sem produtos

```text
Cardápio: Pendente
Nenhum produto cadastrado
```

Ação:

```text
Preencher cardápio
```

### Com produtos

```text
Cardápio: Preenchido
X produtos cadastrados
```

Ação:

```text
Editar cardápio
```

### Acesso expirado

```text
Acesso expirado
Edição do cardápio bloqueada
```

O bloqueio deve valer para gerente e vendedor. Master e lojista seguem suas permissões atuais.

---

## Card da loja

Exibir separadamente:

* Nome, logo e localização;
* Proprietário;
* Tipo da loja;
* Data de criação;
* Status operacional;
* Status do Mercado Pago;
* Visibilidade no WhatsApp;
* Loja de teste;
* Cardápio e quantidade de produtos;
* Acesso temporário;
* Data de expiração;
* Pedidos;
* Leads;
* Percentual de ativação.

Alterar o título **Token** para:

```text
Acesso temporário
```

Exibir a credencial mascarada:

```text
•••••••••• [Copiar]
```

Ao copiar:

```text
Acesso copiado
```

Exibir informações reais de expiração:

```text
Expira em 12 dias
Expira amanhã
Expira hoje às 18:30
Acesso expirado
Expirado em 28/07/2026
Válido até 13/08/2026 às 10:00
```

---

## Ações do card

Ações principais:

* Ver detalhes;
* Preencher cardápio ou Editar cardápio.

Menu de três pontos:

* Configurar Mercado Pago;
* Marcar como loja de teste;
* Remover modo de teste;
* Ativar acesso;
* Desativar acesso;
* Desativar loja;
* Reativar loja;
* Visualizar histórico.

---

## Percentual de ativação

Não mostrar `100%` enquanto houver pendências obrigatórias.

Considerar:

* Dados básicos;
* Proprietário;
* Endereço;
* Cardápio;
* Produtos;
* Mercado Pago;
* Horário de funcionamento;
* Configurações obrigatórias.

Para lojas de teste, ignorar Mercado Pago somente quando:

```text
is_test_store = true
```

Exemplo:

```text
Ativação: 60%
Pendente: integração com o Mercado Pago
```

---

## Cenários obrigatórios

1. Loja recém-criada fica em onboarding e oculta.
2. Loja real sem Mercado Pago permanece oculta.
3. Loja real com Mercado Pago aparece no WhatsApp.
4. Loja mockada ativa aparece sem Mercado Pago.
5. Loja mockada desativada não aparece.
6. Gerente acessa e edita dentro dos 15 dias.
7. Após a expiração, gerente não acessa nem edita.
8. Administrador consegue liberar novamente o acesso.
9. Desativar acesso bloqueia apenas gerente ou vendedor.
10. Desativar loja bloqueia toda a operação.
11. Links antigos validam o estado atual no backend.
12. Loja de teste não realiza cobrança real.
13. Reativação valida novamente todos os requisitos.

---

## Pontos técnicos para análise

Verificar:

* Migration e tabela de lojas;
* Model;
* Serviço de criação;
* Status inicial;
* Autenticação temporária;
* Middlewares;
* Policies e permissões;
* Expiração dos 15 dias;
* Integração, callback e webhook do Mercado Pago;
* Serviço de ativação;
* Consultas usadas pelo WhatsApp;
* Cache de lojas, categorias e produtos;
* Endpoints do cardápio;
* Pedidos;
* Carrinho;
* Checkout;
* Desativação e reativação;
* Auditoria.

Todas as regras de segurança devem existir no backend. O frontend deve apenas representar os estados.

---

## Entrega esperada

### Antes das alterações

* Diagnóstico do fluxo atual;
* Problemas encontrados;
* Campos existentes;
* Regras no backend;
* Regras somente no frontend;
* Alterações necessárias.

### Após as alterações

* Arquivos modificados;
* Migrations criadas;
* Campos adicionados;
* Services e controllers alterados;
* Middlewares e policies alterados;
* Consultas do WhatsApp corrigidas;
* Testes realizados;
* Resultado dos cenários;
* Riscos e pendências.

---

## Critérios de aceite

A tarefa estará concluída quando:

* Loja recém-criada ficar oculta;
* Loja real sem Mercado Pago não aparecer;
* Loja mockada puder aparecer com `is_test_store = true`;
* Loja desativada nunca aparecer;
* Loja de teste não realizar cobrança real;
* Mercado Pago for validado no backend;
* Acesso durar 15 dias corridos desde a criação;
* Copiar credencial não renovar o prazo;
* Expiração bloquear login e edição;
* Administrador puder liberar novamente;
* Liberar acesso não ativar a loja;
* Desativar acesso não afetar a operação;
* Desativar loja retirar toda a operação;
* Links antigos, carrinho e checkout validarem o estado atual;
* Status aparecerem separadamente no card;
* Todos os cenários forem testados;
* Diagnóstico for apresentado antes das alterações.

---

## Regras finais

```text
Loja real disponível
=
is_active = true
E
Mercado Pago conectado
```

```text
Loja mockada disponível
=
is_active = true
E
is_test_store = true
```

```text
Loja desativada
=
is_active = false
E
Nunca aparece no WhatsApp
```

```text
Acesso do gerente
=
seller_access_enabled = true
E
Dentro dos 15 dias ou liberado manualmente
```

```text
Desativar acesso
=
Bloquear somente login e edição do gerente ou vendedor
```

```text
Desativar loja
=
Retirar a loja do WhatsApp e bloquear toda a operação
```
