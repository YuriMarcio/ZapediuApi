# 🛵 Zapediu — Fluxo Conversacional Completo (WhatsApp)

> Documento consolidado: entrada, navegação, carrinho, customização de produto (hambúrguer e pizza), checkout, pagamento e localização.

---

## 0. Visão geral do fluxo

```
ENTRADA (saudação ou NLP)
   │
   ├─► Ver Lojas ──────┐
   │                    ├─► [Categorias da loja, se aplicável] ─► Produtos
   ├─► Ver Categorias ──┘
   │
   └─► NLP detectou produto/loja direto (pula pro carrossel certo)

Produtos ─► Customização (adicionais / tamanho+sabor+borda) ─► Confirmação + total parcial
   │
   ▼
Carrinho ─► Finalizar Compra ─► Localização/Endereço ─► Resumo + Pagamento ─► Confirmação final
```

---

## 1. Entrada

**Saudação genérica** ("oi", "boa noite"):
```
Olá! 👋 Bem-vindo ao Zapediu!
Estou aqui pra matar sua fome em poucos segundos. 🛵💨

[ 🏪 Ver Lojas ] [ 🍔 Ver Categorias ] [ ❓ Como funciona ]
```

**Cliente já pede algo** (NLP/Gemini, mensagem >13 caracteres):
- IA retorna JSON `{intent, tipo, item, match}`
- `match:false` → mensagem de fallback ("Não entendi, pode repetir?") e reabre o menu principal
- `tipo:"loja"` → carrossel de lojas (geral)
- `tipo:"produto"` → carrossel de lojas/produtos filtrados pelo item pedido

Isso pula o menu principal — o cliente não perde tempo clicando em nada que ele já disse.

---

## 2. Ordem: Loja primeiro ou Categoria primeiro?

Mantenha os dois caminhos como **entradas paralelas**. Eles atendem intenções diferentes e convergem no mesmo lugar (carrossel de produtos):

| Caminho | Quando o cliente usa | Ordem |
|---|---|---|
| **Ver Lojas** | Já sabe onde quer comprar | Loja → Categorias → Produtos |
| **Ver Categorias** | Sabe o que quer comer, não sabe onde | Categoria → Lojas → Produtos |

---

## 3. Mostrar categorias da loja? (regra condicional)

- **Loja com 2+ categorias** → mostra o carrossel de categorias antes dos produtos.
- **Loja com 1 categoria só** (ex: hamburgueria pura) → **pula direto pros produtos**.
- Regra prática: `se count(categorias_da_loja) <= 1 → pular etapa`

---

## 4. Carrosséis (lojas / categorias / produtos)

Regra única de paginação, reaproveitada em todos os carrosséis do sistema:

- **9 itens reais + 1 card "Ver mais"** = 10 cards (limite do WhatsApp)
- Última página sem itens suficientes → **não mostra** o card "Ver mais"
- Payload do botão "Ver mais" carrega contexto pra buscar a próxima página:
```json
{
  "action": "ver_mais",
  "contexto": "produtos",
  "loja_id": "123",
  "categoria_id": "lanches",
  "offset": 9,
  "limit": 9
}
```

**Limites técnicos do WhatsApp a respeitar sempre:**
- Botões de resposta rápida (quick reply): **máx. 3 por mensagem**
- Carrossel de template: **máx. 10 cards**, todos com o mesmo tipo de mídia (imagem OU vídeo, nunca misturado)
- Lista interativa (list message): **máx. 10 itens**
- Header de card aceita **imagem (JPG/PNG, até 5MB)** ou **vídeo (MP4, até 16MB)** — **GIF não é suportado**

---

## 5. Adicionar produto — casos simples (sem customização)

Cada card de produto tem dois botões:
```
🍔 Hambúrguer artesanal com bacon crocante e cheddar

[ 😋 Quero um desse! ]      → adiciona 1 unidade direto
[ 🔢 Quero mais de um ]     → abre seletor de quantidade
```

### 5.1 "Quero um desse!"
Adiciona 1 unidade e entra na fila do debounce.

### 5.2 "Quero mais de um" (lista interativa, não texto livre)
```
Quantas unidades de "Hambúrguer artesanal com bacon crocante e cheddar" você quer?
[2] [3] [4] [5] [6] [7] [8] [9] [10+]
```
- 2 a 9 → adiciona direto
- "10+" → única exceção que pede texto: "Digite a quantidade desejada (número)"

---

## 6. Customização de produto — Hambúrguer (adicionais)

Depois de escolher quantidade, entra o passo de adicionais **antes** da confirmação final.

**Passo 1 — pergunta se quer adicional**
```
🍔 Hambúrguer artesanal com bacon crocante e cheddar
Quer colocar algum adicional?
[ ➕ Sim, quero adicionais ] [ 🚫 Não, obrigado ]
```

**Passo 2 — lista de adicionais com seleção múltipla**
```
➕ Escolha os adicionais que quiser (pode marcar mais de um):

📋 Ver adicionais
   🥓 Bacon extra — R$ 4,00
   🧀 Cheddar extra — R$ 3,00
   🍳 Ovo — R$ 3,50
   🧅 Cebola caramelizada — R$ 3,00
```
A lista permanece clicável — o cliente pode tocar nela várias vezes seguidas (Bacon extra, depois Cheddar extra) e o backend registra cada seleção na fila, sem responder a cada toque.

**Passo 3 — debounce (2-3s sem novo toque) → confirmação única, já consolidada**
```
✅ Hambúrguer artesanal com bacon crocante e cheddar adicionado!
➕ Bacon extra — R$ 4,00
➕ Cheddar extra — R$ 3,00
🛒 Total parcial: R$ 39,90

[ 🛒 Ver carrinho ] [ 🛒 Finalizar Compra ]
```

**Regra de negócio**: se o cliente pediu 3x do mesmo hambúrguer, os adicionais escolhidos valem pra todas as unidades daquela linha. Se ele quiser unidades com configurações diferentes, cada configuração vira uma linha separada no carrinho (repete o fluxo "Quero um desse!" pra cada uma).

---

## 7. Customização de produto — Pizza (tamanho, sabores, borda)

### Card da pizza no carrossel (sem botão "Adicionar")
```
🍕 Calabresa
Molho de tomate, calabresa fatiada, cebola, orégano
[ 🍕 Escolher tamanho ]
```

### Passo 1 — Tamanho (até 3 opções, com preço em cada uma)
```
🍕 Calabresa — escolha o tamanho:
[ P — R$ 32,90 ] [ M — R$ 42,90 ] [ G — R$ 52,90 ]
```

### Passo 2 — Sabor extra (depende do tamanho, configurável por loja)
- **Tamanho P** → normalmente não permite combinar sabor → pula direto pro Passo 3
- **Tamanhos M/G** → pergunta, mas é **opcional**:
```
🍕 Pizza Calabresa (Grande)
Quer combinar com outro sabor? É opcional.
[ 🍕 Sim, quero combinar ] [ ➡️ Não, só Calabresa ]
```

**Se sim → carrossel de sabores** (carrossel, não lista):
```
🍕 Escolha o 2º sabor:
[Card] Portuguesa — [Escolher esse sabor]
[Card] Frango c/ Catupiry — [Escolher esse sabor]
[Card] Quatro Queijos — [Escolher esse sabor]
...
[Card 10] ➡️ Ver mais sabores
```
Se o tamanho permitir mais de 2 sabores, depois de escolher o 2º pergunta de novo:
```
[ ➕ Adicionar mais um sabor ] [ ✅ Finalizar sabores ]
```
até bater o máximo configurado — aí para automaticamente.

### Passo 3 — Borda (carrossel; a tradicional é a opção grátis dentro do próprio carrossel)
```
🧀 Escolha a borda:
[Card] Tradicional — Grátis — [Escolher]
[Card] Catupiry — +R$ 8,00 — [Escolher]
[Card] Chocolate — +R$ 10,00 — [Escolher]
```
Não precisa de botão "pular" separado — "Tradicional (grátis)" já cumpre esse papel.

### Confirmação final
```
✅ Pizza Grande adicionada!
🍕 Calabresa + Frango com Catupiry
🧀 Borda: Catupiry (+R$ 8,00)
🛒 Total parcial: R$ 60,90

[ 🛒 Ver carrinho ] [ 🛒 Finalizar Compra ]
```

### 7.1 Configuração do lojista (painel Zapediu)

Nada disso pode ser fixo no código — precisa vir de configuração por produto/loja:

```json
{
  "produto_id": "pizza_calabresa",
  "tamanhos": [
    { "nome": "P", "preco_base": 32.90, "permite_combinar_sabor": false, "max_sabores": 1 },
    { "nome": "M", "preco_base": 42.90, "permite_combinar_sabor": true, "max_sabores": 2, "obrigatorio": false },
    { "nome": "G", "preco_base": 52.90, "permite_combinar_sabor": true, "max_sabores": 3, "obrigatorio": false }
  ],
  "tem_bordas": true,
  "bordas": [
    { "nome": "Tradicional", "preco": 0 },
    { "nome": "Catupiry", "preco": 8.00 },
    { "nome": "Chocolate", "preco": 10.00 }
  ],
  "regra_preco_combinado": "mais_caro"
}
```

- `regra_preco_combinado`: `"mais_caro"` (cobra o valor do sabor mais caro entre os escolhidos — padrão de mercado) ou `"media"` (cobra a média dos sabores). **Decisão de negócio pendente de confirmação.**

### 7.2 Motor único de customização (recomendado)

Em vez de hardcodar "fluxo pizza" e "fluxo hambúrguer" separados, use um config genérico por produto que o bot lê e decide dinamicamente quais passos mostrar:

```json
{
  "produto_id": "...",
  "tem_tamanho": true,
  "tamanhos": [...],
  "permite_combinar_sabor": true,
  "sabores": [...],
  "opcoes_borda": [...],
  "adicionais": [...]
}
```
- `tem_tamanho: true` → mostra passo de tamanho
- `permite_combinar_sabor: true` → mostra passo de 1 ou mais sabores
- `adicionais.length > 0` → mostra passo de adicionais (funciona pra hambúrguer, açaí, pizza, etc.)
- `opcoes_borda.length > 0` → mostra passo de borda

Isso atende qualquer categoria de produto que o lojista cadastrar no futuro, sem código novo por categoria.

---

## 8. Debounce e confirmação (regra geral)

O bot aguarda **2 a 3 segundos** depois do último clique pra agrupar múltiplas adições numa única mensagem:

```
✅ Itens adicionados:
• 3x Hambúrguer artesanal com bacon crocante e cheddar
• 1x Batata Crocante 06
🛒 Total parcial: R$ 122,60

[ 🛒 Ver carrinho ] [ 🛒 Finalizar Compra ]
```

`Finalizar Compra` pula direto pro resumo do carrinho + confirmação de endereço, sem tela intermediária — pra quem já sabe que terminou de escolher. "Continuar comprando" não é necessário como botão: o cliente já pode rolar pra cima e clicar em mais produtos livremente (regra de nunca reenviar o cardápio).

---

## 9. Regra de carrinho: mono-loja

```
⚠️ Seu carrinho tem itens da Burger House.
[ 🗑️ Trocar de loja ] [ ↩️ Continuar na loja atual ]
```

---

## 10. Carrinho

```
🛒 Seu carrinho:
• 3x Hambúrguer artesanal com bacon crocante e cheddar — R$ 89,70
• 1x Batata Crocante 06 — R$ 16,65
🍱 Subtotal: R$ 106,35

[ 🗑️ Esvaziar carrinho ] [ 📝 Adicionar observação ] [ 🛒 Finalizar Pedido ]
```

Remoção é só por item inteiro (não fracionada). Se quiser menos, esvazia e readiciona.

### 10.1 Observação — sempre vinculada a um item específico

Texto livre solto colide com o NLP (o bot não sabe se "sem cebola" é observação ou nova intenção). Por isso a observação é sempre iniciada por botão e vinculada a um item, nunca captura texto livre "no ar".

Como colocar 1 botão por produto do carrinho estoura o limite de 3 quick-replies, o clique em **"📝 Adicionar observação"** abre uma **lista interativa** (até 10 itens) com os produtos do carrinho:

```
📝 Para qual item você quer deixar uma observação?

📋 Selecionar item
   🍔 Hambúrguer artesanal com bacon crocante e cheddar (3x)
   🍟 Batata Crocante 06 (1x)
```

Selecionou o item → pede o texto:
```
📝 Pode digitar a observação para o Hambúrguer artesanal (3x):
(ex: sem cebola, ponto da carne, sem maionese...)
```

Digitou → salva vinculado ao item e confirma:
```
📝 Observação anotada!
Quer adicionar mais alguma observação?
[ 📝 Adicionar outra ] [ 🛒 Voltar ao carrinho ]
```

Se o carrinho tiver mais de 10 itens distintos, a última linha vira "Ver mais itens" (mesma lógica de paginação dos carrosséis).

No resumo final, a observação aparece indentada sob o item:
```
🧾 Resumo do Pedido: #ZAP-2604
• 3x Hambúrguer artesanal com bacon crocante e cheddar — R$ 89,70
   📝 Sem cebola
• 1x Batata Crocante 06 — R$ 16,65
🍱 Subtotal: R$ 106,35
```

---

## 11. Localização e endereço

Use o recurso nativo **Location Request Message** do WhatsApp Business API antes de pedir nome/endereço por texto — dá o ponto exato no mapa, mais confiável que o cliente digitar rua/número errado.

**Passo 1 — pedir localização**
```
📍 Antes de fechar, compartilha sua localização pra eu confirmar a entrega:
[ 📍 Enviar localização ]
```
Payload técnico:
```json
{
  "type": "interactive",
  "to": "<numero_do_cliente>",
  "interactive": {
    "type": "location_request_message",
    "body": { "text": "Pra calcular sua entrega, compartilha sua localização 📍" },
    "action": { "name": "send_location" }
  }
}
```
O cliente escolhe entre localização atual (GPS) ou escolher no mapa. Você recebe latitude/longitude por webhook.

**Passo 2 — geocodificação reversa** (Google Maps API, Mapbox ou Nominatim/OSM) transforma lat/long em endereço legível → mostra pro cliente confirmar:
```
📍 Encontrei este endereço:
Rua dos Testes, 123 — Bairro Tal
Está correto?
[ ✅ Confirmar ] [ ✏️ Corrigir manualmente ]
```

**Passo 3 — completar cadastro**
```
👤 Show! Só falta seu nome completo pra eu finalizar o cadastro:
```
```
📌 Tem algum ponto de referência? (ex: "perto do mercado", "portão azul")
```

**Pontos importantes:**
- Location Request Message só funciona dentro da janela de 24h de conversa ativa; fora disso precisa mandar um template aprovado antes.
- Sempre ter fallback `[✏️ Corrigir manualmente]` — cliente pode recusar ou não ter GPS ativo.
- Salvar lat/long junto com o texto do endereço — é o que a loja/entregador usa pra rota de verdade.

Se o cliente já tem cadastro, o bot pula direto pra confirmação:
```
📍 Confirmando sua entrega:
Rua dos Testes, 123
📌 Ref: Atacadão
Está correto?
[ ✅ Confirmar Endereço ] [ ✏️ Alterar Endereço ]
```

---

## 12. Resumo + Pagamento (fundidos)

```
🧾 Resumo do Pedido: #ZAP-2604
• 3x Hambúrguer artesanal com bacon crocante e cheddar — R$ 89,70
   📝 Sem cebola
• 1x Batata Crocante 06 — R$ 16,65
🍱 Subtotal: R$ 106,35
🛵 Taxa de entrega: R$ 6,00
💰 Total: R$ 112,35
⏳ Tempo estimado: 40-50 min

[ 🔗 Pagar e Confirmar ]
```

### 12.1 Formas de pagamento pelo WhatsApp

O WhatsApp **não tem checkout nativo de compra dentro da conversa**, amplamente disponível no Brasil (WhatsApp Pay existe, mas é pra transferência P2P entre pessoas, não pra loja receber pagamento de pedido). O padrão de mercado — e o que já está no seu fluxo — é o botão de **link de pagamento**, que abre um checkout externo via gateway:

| Forma de pagamento | Como chega no cliente |
|---|---|
| **Pix** | QR code ou "Pix Copia e Cola" na tela do gateway |
| **Cartão de crédito** | Formulário do gateway, com parcelamento |
| **Cartão de débito** | Formulário do gateway |
| **Boleto** | Menos comum em delivery (demora pra compensar) |
| **Dinheiro/maquininha na entrega** | Botão separado, sem gateway: `[💵 Pagar na entrega]` |

Gateways comuns: **Mercado Pago, PagSeguro, Stone, Pagar.me, Asaas, Stripe**. Todos geram link de checkout com Pix + cartão juntos e mandam webhook de confirmação — gatilho pra disparar a mensagem de pagamento confirmado automaticamente.

---

## 13. Pós-pagamento

```
✅ Pagamento confirmado!
Seu pedido #ZAP-2604 já foi enviado pra Burger House. 🔥
Te aviso assim que sair pra entrega. 🛵
```

---

## 14. Checklist geral de regras de negócio

- [ ] Botões de resposta rápida: máx. 3 por mensagem
- [ ] Carrossel de template: máx. 10 cards, mesmo tipo de mídia (sem GIF)
- [ ] Lista interativa: máx. 10 itens, com paginação "Ver mais" além disso
- [ ] Debounce de 2-3s em toda ação de "adicionar" (produtos e adicionais)
- [ ] Carrinho trava em 1 loja por vez
- [ ] Categoria da loja só aparece se `count(categorias) > 1`
- [ ] Observação sempre vinculada a um item, nunca por texto livre solto
- [ ] Customização de produto (tamanho/sabor/borda/adicionais) 100% configurável por produto no painel do lojista, não hardcoded por categoria
- [ ] Regra de preço em pizza meio a meio configurável (`mais_caro` ou `media`)
- [ ] Location Request Message antes de pedir endereço por texto
- [ ] Pagamento via link de gateway (Pix + cartão + boleto), com opção de pagamento na entrega
- [ ] Nunca reenviar bloco explicativo do cardápio (rolagem nativa do WhatsApp)