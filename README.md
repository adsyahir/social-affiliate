# Social Affiliate Automation

Laravel 13 app that writes affiliate posts with AI, sends drafts to Telegram for approval,
publishes them to social accounts (affiliate link always goes in a reply), and tracks performance.

## Local development (Herd)

Herd serves PHP; Docker runs only Postgres and Redis.

```bash
cp .env.example .env        # then set DB_PASSWORD
php artisan key:generate
docker compose up -d postgres redis
php artisan migrate --seed
herd link social-affiliate   # Herd only parks ~/Desktop/Herd one level deep
php artisan queue:work       # in a second terminal, once jobs exist
```

The app is then at http://social-affiliate.test. The seeder creates the example setup from the brief:
an Amazon and a Shopee affiliate account, two Threads accounts with personas, and one campaign each.

Tests use in-memory SQLite by default: `php artisan test`.

## VPS (full Docker stack)

```bash
cp .env.example .env        # set APP_ENV=production, APP_DEBUG=false, APP_URL, APP_KEY, DB_PASSWORD
docker compose up -d --build
```

| Service     | What it runs                                         |
|-------------|------------------------------------------------------|
| `app`       | nginx + php-fpm on port `APP_PORT` (default 8080); runs migrations on start |
| `queue`     | `php artisan queue:work redis`                       |
| `scheduler` | `php artisan schedule:work`                          |
| `postgres`  | PostgreSQL 17, port bound to 127.0.0.1 only          |
| `redis`     | Redis 7 with append-only persistence, 127.0.0.1 only |

Put a reverse proxy with HTTPS (Caddy, Nginx Proxy Manager, Traefik) in front of `app`;
Telegram webhooks require HTTPS.

## Supported programs and platforms

| Driver | Setup | Notes |
|---|---|---|
| Shopee (affiliate) | Optional Open API `app_id` / `app_secret` | Without them, paste the dashboard short link on each product. Catalogue import needs the API. |
| Amazon (affiliate) | Store tag in the account's **Tracking ID** (e.g. `yourstore-20`) | Links are rebuilt as `/dp/ASIN?tag=…` from the product page URL; `amzn.to` links are used as-is. No catalogue search (PA-API 5 was retired in 2026). |
| Threads | Long-lived token; refreshed daily | Insights: views, likes, replies, reposts. |
| Facebook Page | Page access token made from a long-lived user token (does not expire); permissions `pages_manage_posts`, `pages_manage_engagement`, `pages_read_engagement`, `read_insights` | The link reply is a comment by the Page. Graph API version: `FACEBOOK_GRAPH_VERSION` (default `v25.0`). |
| Telegram channel | Platform account ID `@channel` (or `-100…`), access token = a bot token; the bot must be a channel admin | The link reply is a reply message with a link preview. No insights (the Bot API has no view counts). |

Use **Connection → Check connection** and **Send test post** on each social account before going live.

## Telegram approvals

Drafts arrive in Telegram with Approve / Reject / Regenerate buttons. Replying to a draft's message
with text rewrites it using your reply as feedback. Approve publishes the post, then the link reply.

1. Create a bot with [@BotFather](https://t.me/BotFather) and copy its token.
2. Send your bot any message, then find your chat ID (for example by opening
   `https://api.telegram.org/bot<TOKEN>/getUpdates` and reading `message.chat.id`).
3. Set in `.env`:

   ```bash
   TELEGRAM_BOT_TOKEN=123456:ABC...
   TELEGRAM_CHAT_ID=123456789
   TELEGRAM_ALLOWED_USER_IDS=        # optional: comma-separated user IDs allowed to approve
   TELEGRAM_WEBHOOK_SECRET=          # random letters/numbers, e.g. from: openssl rand -hex 16
   ```

4. Choose how Telegram reaches the app:
   - **VPS with HTTPS:** `php artisan telegram:set-webhook` (uses `APP_URL/telegram/webhook`).
   - **Local or no HTTPS:** keep `php artisan telegram:poll` running. Polling and a webhook
     cannot be used at the same time.

A queue worker must be running for drafts, rewrites and publishing to happen.

## Scheduled drafts

`campaigns:run` runs every 15 minutes (scheduler service). Each active campaign spreads its
`posts_per_day` evenly over a daily window, by default 08:00–22:00 Malaysia time
(`CAMPAIGN_TIMEZONE`, `CAMPAIGN_WINDOW_START`, `CAMPAIGN_WINDOW_END`). When a slot is due, the
campaign's next product (never drafted before) gets a draft for every social account in the
campaign, and each draft goes to Telegram for approval.

- After downtime, missed slots are caught up one batch per run, not all at once.
- If a draft cannot be written, Telegram says why and the product is tried again at a later slot.
- When a campaign runs out of products, Telegram says so once a day.
- **Run now** on the Campaigns page starts a batch immediately without using up a slot.

## Insights

`insights:fetch` runs daily at 06:00 (campaign timezone) and saves a views / likes / replies /
reposts snapshot for every post published in the last `INSIGHTS_DAYS` (default 30). Snapshots
accumulate, so growth over time is kept. The Posts list shows each post's latest numbers, a post's
page has **Refresh insights**, and the dashboard sums the last 7 days. Fetch failures (for example
an expired token) are logged rather than sent to Telegram.

## Configuration notes

- `ANTHROPIC_API_BASE_URL` (not `ANTHROPIC_BASE_URL`) overrides the Claude endpoint. Tools such as
  Claude Code set `ANTHROPIC_BASE_URL` in the shell, and a real environment variable wins over `.env`.
- Threads tokens are refreshed daily at 03:00 by `social:refresh-tokens` (scheduler service).

## Data model

- `affiliate_accounts`: program (amazon, shopee, tiktok_shop), tracking ID, encrypted credentials
- `social_accounts`: platform, platform account ID, encrypted access token, persona (JSON)
- `products`: belongs to an affiliate account; `used_personally` + `review_notes` gate first-person claims
- `campaigns` + `campaign_social_account`: one affiliate account to many social accounts
- `posts`: main content, link reply content, status, external IDs. Saving a post whose account or
  product is outside its campaign throws `CampaignMismatchException`.
- `post_insights`: metric snapshots per post
- `post_replies`: replies people leave on our posts (untrusted input), with AI draft and status
