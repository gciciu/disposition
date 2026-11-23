# SMTP outbound email — setup

Delivery sends mail via **Symfony Mailer** (`MAILER_DSN` / `MAIL_FROM` in `backend/.env.local`). There is no built-in mail server — use an external SMTP provider.

Used for:

- Admin → **Email** → test messages
- Customer delivery confirmation (**Lieferbestätigung**) with PDF attachment

Replace:

| Placeholder | Meaning |
|-------------|---------|
| `USER` / `PASSWORD` | SMTP username and password (URL-encode special chars) |
| `smtp.example.com` | Your provider’s SMTP host |
| `/var/www/delivery` | Project root — change everywhere if different |
| `ADMIN_HOST` | Admin domain, e.g. `delivery.produkt-konfigurator24.de` |

---

### 1. Choose a provider

| Option | Examples |
|--------|----------|
| Hosted mailbox | Google Workspace, Microsoft 365, Zoho Mail, Amazon WorkMail |
| Transactional SMTP | Amazon SES, SendGrid, Mailgun, Postmark, SMTP2GO |

Create a dedicated sender, e.g. `noreply@your-domain.com`. Prefer a domain you control so SPF/DKIM can be set.

Collect from the provider:

- SMTP host
- Port: **587** (STARTTLS) or **465** (SMTPS)
- Username (often the full email)
- Password or SMTP/API credential

---

### 2. Backend env

In `/var/www/delivery/backend/.env.local`:

```bash
# Port 587 + STARTTLS (most providers)
MAILER_DSN=smtp://USER:PASSWORD@smtp.example.com:587

# Port 465 + implicit TLS
# MAILER_DSN=smtps://USER:PASSWORD@smtp.example.com:465

MAIL_FROM=noreply@your-domain.com
```

Rules:

- `MAIL_FROM` must be a **valid email**; it becomes the From address (display name: `Delivery`).
- Prefer that `MAIL_FROM` matches a mailbox/domain the SMTP provider is allowed to send for.
- If the password (or user) contains `@`, `:`, `/`, `#`, `%`, etc., **URL-encode** it in the DSN  
  (`@` → `%40`, `#` → `%23`, `/` → `%2F`).
- `null://null` accepts messages but **does not deliver** — never use in production.

Examples:

```bash
# Google Workspace / Gmail (app password if 2FA)
MAILER_DSN=smtp://noreply%40your-domain.com:APP_PASSWORD@smtp.gmail.com:587

# Microsoft 365
MAILER_DSN=smtp://noreply%40your-domain.com:PASSWORD@smtp.office365.com:587

# SendGrid
MAILER_DSN=smtp://apikey:SECRET_KEY@smtp.sendgrid.net:587
```

After changing mail env vars:

```bash
cd /var/www/delivery/backend
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod
```

---

### 3. DNS (deliverability)

At the DNS host for the `MAIL_FROM` domain:

1. **SPF** — TXT allowing your SMTP provider, e.g.  
   `v=spf1 include:_spf.google.com ~all` (use your provider’s include).
2. **DKIM** — enable in the provider UI; publish the TXT/CNAME records they give you.
3. **DMARC** (recommended) — e.g.  
   `v=DMARC1; p=none; rua=mailto:you@your-domain.com`

Without SPF/DKIM, messages often land in spam or are rejected.

---

### 4. Test

1. Log in to admin: `https://ADMIN_HOST/`
2. Open **Email**.
3. Send a **test email** to your own address.
4. Optionally send a **test confirmation** (sample Lieferbestätigung + PDF).

Or via API (valid admin JWT):

```bash
curl -s -X POST https://ADMIN_HOST/api/admin/email/test \
  -H "Authorization: Bearer YOUR_JWT" \
  -H "Content-Type: application/json" \
  -d '{"to":"you@example.com"}'
```

Expect success and a real inbox message. If the response mentions `null transport`, `MAILER_DSN` is still `null://null`.

---

### 5. Local development (optional)

Use Mailpit instead of a real SMTP server:

```bash
# Windows
.\scripts\start-mailpit.ps1
```

```bash
# backend/.env.local
MAILER_DSN=smtp://127.0.0.1:1025
MAIL_FROM=noreply@delivery.local
```

- SMTP: `127.0.0.1:1025`
- UI: http://localhost:8025

---

### Troubleshooting

| Symptom | Check |
|---------|--------|
| “null transport” / no delivery | Real `MAILER_DSN` (not `null://null`), then `cache:clear` / `cache:warmup` |
| Auth / TLS errors | Port 587 + `smtp://` vs 465 + `smtps://`; correct host/user; provider allows your server IP |
| Password with special chars fails | URL-encode in the DSN |
| Mail goes to spam | SPF + DKIM (+ DMARC) for the `MAIL_FROM` domain |
| Invalid From | Set a valid `MAIL_FROM` email |

`COMPANY_*` in `.env.local` only affect the **PDF letterhead**, not SMTP.
