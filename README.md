# DeliveryZap API + Painel Admin

Projeto Laravel preparado para:

- Receber webhook da Z-API em rota de API.
- Exibir painel web administrativo para acompanhamento de entregas.
- Rodar com Docker Compose incluindo MySQL, Adminer e ngrok apontando para a URL local.

## Stack

- Laravel 12
- PHP-FPM 8.3 + Nginx
- MySQL 8.4
- Adminer
- ngrok

## Estrutura importante

- API webhook: `POST /api`
- Painel web: `GET /` e `GET /admin/deliveries`
- Serviço de webhook: `app/Services/Zapi/ZapiWebhookService.php`
- Cliente Z-API: `app/Services/Zapi/ZapiClient.php`

## Subindo com Docker

1. Ajuste as variáveis no `.env`:
	- `ZAPI_INSTANCE_ID`
	- `ZAPI_INSTANCE_TOKEN`
	- `ZAPI_CLIENT_TOKEN`
	- `ZAPI_WEBHOOK_TOKEN`
	- `NGROK_AUTHTOKEN`
2. Suba os containers:

```bash
docker compose up -d --build
```

3. Acesse os serviços:
	- App Laravel (Nginx): `http://localhost:8080`
	- Adminer: `http://localhost:8081`

O container do ngrok encaminha para a URL local `http://localhost:8080` do host.

## Configuração do webhook na Z-API 

1. Rode `docker compose logs -f ngrok` e copie a URL HTTPS ativa exibida nos logs do túnel.
2. Configure webhook da Z-API para:

```text
https://URL-PUBLICA-NGROK/api
```

3. Envie o header:

```text
X-Webhook-Token: valor-de-ZAPI_WEBHOOK_TOKEN
```

## Banco no Adminer

Use os valores do `.env`:

- System: `MySQL`
- Server: `db`
- Username: `deliveryzap`
- Password: `secret`
- Database: `deliveryzap`

## Fluxo do webhook

1. Evento da Z-API chega em `/api`.
2. Controller valida token do header.
3. Payload bruto é salvo em `webhook_events`.
4. Dados normalizados são salvos/atualizados em `deliveries`.
5. Painel web lista os pedidos e métricas.

## Comandos úteis

```bash
# logs gerais
docker compose logs -f

# logs só da aplicação
docker compose logs -f app

# entrar no container app
docker compose exec app sh

# rodar comando artisan
docker compose exec app php artisan route:list
```
