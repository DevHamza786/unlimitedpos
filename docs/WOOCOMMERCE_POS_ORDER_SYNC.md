# WooCommerce / Square → Dollydustcountry POS order sync

This integration pushes **paid WooCommerce orders** to your Dollydustcountry POS Laravel app using **WooCommerce hooks** (no Square API polling). Successful deliveries are stored in `wc_inbound_order_syncs`.

## Folder structure

```
Dollydustcountry-POS/
├── app/Http/Controllers/Api/WcOrderInboundController.php
├── app/Http/Middleware/VerifyWcInboundSyncToken.php
├── app/WcInboundOrderSync.php
├── config/wc_inbound_sync.php
├── database/migrations/*_create_wc_inbound_order_syncs_table.php
├── routes/api.php   # POST /api/wc-inbound/orders
└── wordpress-plugins/ultimatepos-woo-sync/   # copy to wp-content/plugins/
```

## POS (Laravel) setup

1. **Generate a secret** (64 hex chars example):

   ```bash
   php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
   ```

2. **`.env`**:

   ```env
   WC_INBOUND_SYNC_SECRET=your_long_random_secret
   # Optional hardening (must enable same in WP plugin):
   WC_INBOUND_SYNC_REQUIRE_HMAC=false
   WC_INBOUND_SYNC_MAX_SKEW=300
   ```

3. **Migrate**:

   ```bash
   php artisan migrate
   ```

4. **Smoke test** (replace URL and token):

   ```bash
   curl -sS -X GET "https://your-pos-domain.com/api/wc-inbound/ping" \
     -H "Authorization: Bearer YOUR_SECRET"
   ```

5. **Create order** (example):

   ```bash
   curl -sS -X POST "https://your-pos-domain.com/api/wc-inbound/orders" \
     -H "Authorization: Bearer YOUR_SECRET" \
     -H "Content-Type: application/json" \
     -d '{"business_id":1,"order_id":"WC-1001","transaction_id":"sq_xxx","payment_status":"paid","currency":"GBP","total_amount":39.95,"tax":6.66,"customer":{"name":"Jane","email":"jane@example.com","phone":""},"items":[],"created_at":"2026-05-06T12:00:00+00:00"}'
   ```

   - **201** — inserted  
   - **200** + `"duplicate": true` — same `business_id` + `order_id` already stored (idempotent)

## WordPress plugin setup

1. Copy `wordpress-plugins/ultimatepos-woo-sync` to `wp-content/plugins/ultimatepos-woo-sync`.
2. Activate **Dollydustcountry POS WooCommerce Order Sync** in WP admin.
3. **WooCommerce → POS Order Sync**:
   - **POS API URL**: `https://your-pos-domain.com/api/wc-inbound/orders`
   - **API secret**: same as `WC_INBOUND_SYNC_SECRET`
   - **POS business ID**: numeric ID from your `business` table
   - Enable sync, save.

4. **HMAC (optional)**  
   Set **HMAC signatures** in WP and in `.env`:

   ```env
   WC_INBOUND_SYNC_REQUIRE_HMAC=true
   ```

   The plugin sends `X-WC-Sync-Timestamp` and `X-WC-Sync-Signature` = `hash_hmac('sha256', $ts . "\n" . $rawBody, $secret)`.

## Example JSON payload (plugin → POS)

```json
{
  "business_id": 1,
  "order_id": "56",
  "order_key": "wc_order_xxx",
  "transaction_id": "square_or_gateway_id",
  "payment_status": "paid",
  "currency": "GBP",
  "total_amount": 39.95,
  "tax": 0,
  "customer": {
    "name": "John Doe",
    "email": "john@example.com",
    "phone": "+441234567890"
  },
  "items": [
    {
      "id": "12",
      "name": "PILBARA",
      "sku": "RM200CF",
      "quantity": 1,
      "subtotal": 39.95,
      "total": 39.95,
      "tax": 0,
      "product_id": "123"
    }
  ],
  "created_at": "2026-05-04T02:34:58+00:00"
}
```

## Security practices

- Use **HTTPS** everywhere; keep **TLS verification** on in production (plugin + server).
- **Rotate** the shared secret if it leaks; restrict who can read WP `wp_options`.
- **Firewall** `/api/wc-inbound/*` to WordPress server IP if you have a fixed egress IP.
- Enable **HMAC** for replay protection once both sides are configured.
- Laravel **throttle** is applied (`120/min` per IP); adjust in `routes/api.php` if needed.

## Duplicate prevention

- **POS**: unique index `(business_id, wc_order_id)` + `firstOrCreate` idempotent behaviour.
- **WordPress**: order meta `_pos_woo_sync_synced_at` set after success; Action Scheduler **unique** async action per order reduces parallel sends.

## Retries and logging

- Failures log to **WooCommerce → Status → Logs** (`ultimatepos-woo-sync`).
- Retries use exponential backoff (capped) up to **Max retry attempts** in settings.
- Order meta: `_pos_woo_sync_last_error`, `_pos_woo_sync_attempts`, `_pos_woo_sync_last_http_code`.

## Database schema (POS)

Table `wc_inbound_order_syncs`:

| Column | Purpose |
|--------|---------|
| `business_id` | Target business |
| `wc_order_id` | WooCommerce order ID (string) |
| `transaction_id` | Gateway / Square reference |
| `payment_status`, `currency`, `total_amount`, `tax_total` | Summary |
| `customer_*` | Denormalised customer |
| `items` | JSON line items |
| `payload` | Full request snapshot + `received_at`, `ip` |
| `wc_created_at` | Order time from WooCommerce |

## Future scalability

- **Map to `transactions`**: add optional `pos_transaction_id` and a job that creates a real POS sale from `wc_inbound_order_syncs` (product/SKU matching, tax, location).
- **Square webhooks**: implement `POST /wp-json/pos-woo-sync/v1/square` with signature verification; resolve WC order and call `POS_Woo_Sync_Retry::schedule()`.
- **Outbox pattern**: insert queue rows in WP DB and a worker drains them (for very high volume).
- **Multi-site**: one secret per POS business or store tokens in `business` settings instead of a global env secret.

## Square direct API (optional)

In **Business settings** (same page as WooCommerce) you can enable **Square payments (direct API)**:

- Paste **Sandbox** or **Production** access token and **Location ID** from [Square Developer](https://developer.squareup.com/).
- **Test connection** calls Square `GET /v2/locations`.
- **Import payments** calls `GET /v2/payments` for the last *N* days and inserts **COMPLETED** payments into `wc_inbound_order_syncs` with `source = square_api` and `wc_order_id` like `sq:{payment_id}` (deduped per business).

This is **not** WooCommerce polling: it reads Square directly. You can still use the WordPress plugin for order line items from WooCommerce; Square API rows are mainly payment-level (line items often empty unless you extend the importer).

Optional env: `SQUARE_API_VERSION` (defaults in `config/square.php`).

## Troubleshooting

| Symptom | Check |
|---------|--------|
| 401 Unauthorized | Secret mismatch; `Authorization: Bearer` header |
| 503 not configured | `WC_INBOUND_SYNC_SECRET` empty on POS |
| 422 validation | `business_id` invalid; JSON field types |
| No sync from WP | Plugin enabled, URL correct, order **paid**; WC logs |
| Square import empty | Token permissions, correct **Location ID**, environment sandbox vs production |
| Image / unrelated | This doc is **orders only**, not media sync |
