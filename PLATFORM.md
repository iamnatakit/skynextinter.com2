# SkyNext Commerce platform

Local implementation for this CodeIgniter project. The original captured website
remains at `/`; the live database-backed store is `/shop` and administration is
`/admin`. Links from captured product cards open the live store. Snapshot category
and content pages remain historical presentation pages.

## Start

```powershell
python tools/import_catalog.py
php spark platform:install
.\tools\serve-platform.ps1
```

Open http://localhost:88/skynextinter.com/public/admin or http://localhost:88/skynextinter.com/public/shop. The first install
prints a randomly generated administrator password once; subsequent installs do
not change it. Change it under `/account`. Never commit or publish the database,
slips, sessions or `.env`. No fixed shared password is embedded in source code.

Start Apache in XAMPP on port 88. Both launcher scripts check this existing
Apache service; they do not create another PHP server. Apache remains running
after closing the terminal. The project URL includes `/skynextinter.com/public/`.
The project root blocks HTTP access to private source files and `.env`.

For a virtual host, point its document root at `public/` and update
`app.baseURL` in `.env` to match. Configure PHP's `upload_tmp_dir` to a
writable private folder when using file uploads.
The commerce platform uses MySQL/MariaDB through PDO MySQL. Configure
`PLATFORM_DB_DRIVER`, `PLATFORM_DB_HOST`, `PLATFORM_DB_PORT`, `PLATFORM_DB_NAME`,
`PLATFORM_DB_USER`, and `PLATFORM_DB_PASSWORD` in the private `.env` file before
running `php spark platform:install`. Keep the credentials out of source control.
For automated tests, `new Store(':memory:')` continues to use SQLite.
`PLATFORM_DB_PATH` is only used when explicitly selecting the SQLite driver.
All stored timestamps are UTC; the interface displays Bangkok time. Prices are
integer satang.

To transfer an existing SQLite commerce database, set the MySQL connection
values in `.env`, then run `php spark platform:migrate-mysql`. The command backs
up the SQLite file, refuses to overwrite a populated MySQL database, and checks
table row counts after copying. Leave the SQLite file intact until the migrated
site has been verified.

## Implemented workflows

- Users: registration, login/logout, password hashing, session regeneration,
  password changes, administrator password resets, role changes and suspension.
  Password resets invalidate older sessions. Roles: admin, manager, sales, member.
- Catalog: 196 imported products, editable prices, descriptions, categories,
  inventory, visibility and validated local product image uploads. Imported stock
  is zero until the administrator enters actual inventory; public pages cannot
  establish stock quantities.
- Cart and orders: quantities, server-side pricing, coupon validation, shipping,
  stock reservation, duplicate-submit protection, customer order history,
  cancellation before payment, fulfilment and tracking numbers. The worker cancels
  unpaid orders after 24 hours. Orders awaiting slip review are preserved.
- Promotions: fixed/percentage coupons, minimum spend, start/end time and usage
  limits. Coupon consumption and stock changes are transactional.
- Bank transfers: private image upload, exact-amount checks, duplicate-image
  detection, optional EasySlip/SlipOK bank verification, staff fallback and an audit trail.
  Verified automatic slips generate one payment confirmation token per order.
  Configure the receiving bank account in `/admin/settings` before taking payment.
- CRM: customer purchase summaries, lead stages, assigned staff, rule-based lead
  interest scores, activity notes and follow-up tasks.
- Chat: first-party chat widget, persistent per-visitor transcripts, human handoff,
  shared staff inbox, outgoing queue, optional OpenAI, and signed incoming LINE /
  Facebook webhooks. A lead is automatically recorded only when the customer
  supplies contact details and consent. Channel syntax:
  `lead: Name | phone or email | ยินยอม`.
- Affiliate: personal `/r/CODE` links, click counts, immutable sponsor assignment on
  new registrations, referral order counts and descendant listing. Attribution is
  stored using an opaque HttpOnly cookie for 30 days. No income plan, commission,
  rank, position, binary placement or payout exists, as requested.
- Analytics: opt-in page views, session-based anonymous visitor counts, daily bars,
  pages and source reports, enable/disable and age-based retention deletion. No IP
  addresses, URL queries or full referring URLs are saved in analytics. Server logs
  are separate from application analytics. Visitors can change consent in the widget.

## External-service configuration

External services are **not connected or live-tested** until the merchant supplies
credentials and completes sandbox tests. Configure secrets in the private `.env`
or server environment, never in source code or browser JavaScript. The connections
screen displays only whether values exist; it does not expose them.

```dotenv
# Stripe (use sandbox credentials first)
STRIPE_SECRET_KEY = ''
STRIPE_WEBHOOK_SECRET = ''
# Opn / Omise
OPN_SECRET_KEY = ''
# OpenAI: explicitly choose a model available to your account
OPENAI_API_KEY = ''
OPENAI_MODEL = ''
# LINE Messaging API
LINE_CHANNEL_SECRET = ''
LINE_ACCESS_TOKEN = ''
# Facebook Messenger
META_APP_SECRET = ''
META_VERIFY_TOKEN = ''
META_PAGE_TOKEN = ''
META_GRAPH_VERSION = ''
```

Webhook endpoints on your public HTTPS origin:

| Service | Endpoint | Processing |
| --- | --- | --- |
| Stripe | `/hooks/stripe` | Raw-body HMAC signature + 5-minute tolerance; snapshot events `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `checkout.session.async_payment_failed` |
| Opn | `/hooks/opn` | Retrieve the known charge using the merchant secret and verify canonical amount, currency and successful paid status |
| LINE | `/hooks/line` | Raw-body HMAC-SHA256 with channel secret, constant-time comparison and event deduplication |
| Facebook | `/hooks/facebook` | GET challenge using verify token; POST raw-body `X-Hub-Signature-256` verification and message ID deduplication |

Stripe uses hosted Checkout. Opn creates PromptPay charges and provides a link to
the provider's QR image. A success redirect never marks an order paid. Only a
verified provider event can confirm the amount and payment. Gateway initiation
reserves the order in `gateway_pending`; ambiguous requests remain locked for
provider-side reconciliation to prevent accidental double charging. The order detail
screen can retrieve canonical provider status, including attaching a known external
reference after checking the merchant dashboard; metadata and totals are verified. Provider
expiry/failure events release unpaid orders. If a network call is ambiguous or
the worker stops during a send, staff must inspect the provider dashboard before
retrying. Failed/uncertain outgoing messages are visible in the inbox's outbox.
There is no automatic replay of an ambiguous Facebook message send.

Payment refunds are recorded after a staff member issues the refund through the
bank or provider dashboard. This code does not issue real refunds or capture
card details locally. Test late/duplicate payment and expiry events in provider
sandboxes before enabling sales. Configure and validate the slip verification provider before enabling automatic approval.

Enable LINE/Facebook in `/admin/integrations` only after configuring their
credentials, public webhook, permissions and account subscriptions. Select the
Graph API version supported by your Meta app; the version is not guessed. Worker
messages are replies to inbound customer conversations, not outbound campaigns.
Meta messaging permissions and reply windows still apply.

OpenAI is disabled initially. Enter a model and key, enable it in `/admin/ai`, and
obtain customer consent in the web widget. Channel customers can type `ยินยอม AI`
to opt in and `หยุด AI` to opt out. Only the current message and public catalog /
store knowledge are sent. OpenAI is limited to 100 calls per day by this app.
The model has no payment, order mutation or other tool access. Without credentials
or consent the bot uses store knowledge and catalog lookup rules.

## Background work

```powershell
php spark platform:work
```

This processes up to 20 inbound chat jobs and 20 outgoing replies per run, expires
unpaid orders and removes expired referral/rate-limit tokens. Schedule it every
minute on the deployment server, using the project working directory and the
correct PHP executable. Scheduling and external sends have not been enabled by
this installation. If a job is interrupted in `processing` or `sending`, review
its state before restoring it; exactly-once delivery cannot be guaranteed across
external networks. In-app web chat works without a worker.

## Verification

```powershell
php -d extension=sqlite3 vendor/bin/phpunit --no-coverage
```

`tests/platform/CommerceTest.php` uses an in-memory PDO database. For the HTTP smoke
flow, copy the application database to `writable/platform-smoke.sqlite`, add the
`settings` marker `smoke_test=1`, then start a separate test server on port 8081
with `PLATFORM_DB_PATH` pointing to that copy and router `tools/smoke_router.php`.
Set `PLATFORM_TEST_PASSWORD` in the test process environment and run
`python tools/smoke_platform.py`. Do not point this script at a production database.
It creates test users, orders, messages and an uploaded 1-pixel proof image only
in the isolated test workflow. The report is
`writable/platform-smoke-report.json`. Existing real data is not test input.

This is a local single-server implementation. Public production rollout still
requires HTTPS, real bank details/stock, credentials, sandbox and webhook checks,
appropriate server permissions, backups, service monitoring and worker scheduling.
Email delivery / self-service password recovery,
automatic refunds and bulk campaigns are not implemented. Instagram, WhatsApp and
other platforms are not connected; the requested connectors are LINE and Facebook.

## Provider references

- [OpenAI Responses text generation](https://developers.openai.com/api/docs/guides/text)
- [Stripe Checkout session creation](https://docs.stripe.com/api/checkout/sessions/create)
- [Stripe webhook verification](https://docs.stripe.com/webhooks)
- [Opn webhook charge verification](https://docs.opn.ooo/api-webhooks)
- [Opn PromptPay](https://docs.opn.ooo/promptpay)
- [LINE signature verification](https://developers.line.biz/en/docs/messaging-api/verify-webhook-signature/)
- [Facebook Messenger webhooks](https://developers.facebook.com/docs/messenger-platform/webhooks)

## Automatic bank-slip verification and payment token

The live `/payment` page lists the signed-in customer's orders and links to each
order's private slip form. Images remain in private `writable/slips/`. Submitters
see a notice that images are sent to the configured verification provider.

1. Subscribe to EasySlip or SlipOK and register the receiving bank account with
   that provider (for SlipOK, configure the correct API branch in its LINE LIFF).
2. Set private `.env` values; templates are in `env`:

```ini
SLIP_AUTO_VERIFY = 1
SLIP_PROVIDER = 'easyslip'
SLIP_RECEIVER_BANK = '004'
SLIP_RECEIVER_ACCOUNT = 'YOUR_FULL_ACCOUNT_DIGITS'
EASYSLIP_API_KEY = 'YOUR_PRIVATE_KEY'
```

Use actual bank/account values. Bank code is exactly three digits; account is
6–20 digits without separators. To use SlipOK, set `SLIP_PROVIDER = 'slipok'`,
`SLIPOK_API_KEY` and numeric `SLIPOK_BRANCH_ID` instead. Configuration status
appears in `/admin/integrations`. These services require their own account/plan;
OpenAI credentials are not used to authorize transfers.

3. Run `php spark platform:install` after updates. Upload a new slip through its
   order page. Verification runs immediately; `php spark platform:work` handles
   queued jobs left before an API request started. Schedule the worker as needed.

EasySlip uses v2 `/verify/bank` with account matching, expected amount and duplicate
checking. SlipOK uses `log=true` and expected amount. Local checks additionally
verify the bank/account, server-calculated order amount, THB/Thailand, transfer
time (no earlier than five minutes before the order, no older than 24 hours, no
more than five minutes in the future), and a unique sending-bank/transaction key.
Different images or providers cannot reuse the same verified transaction.

Only authoritative successful bank verification can approve automatically. An
LLM/OCR guess cannot approve a payment. Failures, missing settings, masked account
ambiguity and provider timeouts leave payment pending for staff review; an
ambiguous request is not automatically sent again or charged repeatedly. Staff
can inspect provider records and approve/reject manually. Automatic verification
accepts images up to 4 MB; larger accepted uploads require staff review.

Approval, transaction reservation and token creation commit in one SQLite
transaction. A cancellation or staff decision during verification cannot be
overwritten. The payment token (`SNP-` plus 32 random bytes in hexadecimal) is a
receipt reference displayed to the order owner and payment administrators. It
is not a bearer credential, access pass, AI usage balance or redeemable credit;
all order access continues to require an authenticated owner. One token is issued
per automatically verified order and revoked when a refund is confirmed. Manual
approvals and other gateway payments do not issue this slip-verification token.

Official integration contracts:
- https://document.easyslip.com/en/v2/verify/bank/
- https://slipok.com/api-documentation/check-slip/

Provider credentials have not been supplied; external verification and real bank
transfers have not been live-tested. Keep `SLIP_AUTO_VERIFY = 0` until configured.

## Blog and article management

- Public index: `/blog`, added to the shared desktop and mobile navigation.
- Public detail: `/blog/{slug}`. The index displays every published article,
  newest first, with keyword search and category filtering. Drafts, archived
  articles and future publication timestamps are never public.
- Management: `/admin/blog`. Administrators and managers can create, edit, publish,
  upload a JPEG/PNG/WebP cover (up to 5 MB), or archive and restore an article.
  Other roles cannot manage articles. Archive hides content without deleting it.
- Contents are escaped plain text with paragraph breaks. Start a separate
  paragraph with `## ` for a section heading. HTML/scripts are never executed.
- Empty cover fields use a category graphic. Existing validated local images
  can also be used. Slugs accept lowercase ASCII letters, numbers and hyphens;
  a random slug is generated when blank. Slugs must be unique.
- `php spark platform:seed-blog` installs the blog schema and publishes the eight
  original Thai starter articles from `app/Database/blog_seed.json`. Existing
  articles matching these slugs are preserved, including edits and draft states.
  The current active administrator is recorded as their author.
- `tests/platform/BlogTest.php` covers permissions, publish/archive/restore,
  search, all-article listing, validation and seed content. The dedicated HTTP
  workflow `tools/smoke_blog.py` requires the isolated marked smoke database,
  port 8081, and `PLATFORM_TEST_PASSWORD`; it must not run against live data.

## Member LINE routing and affiliate promotion

- `/affiliate`: signed-in member's promotion dashboard with copy buttons, an
  optional LINE share chooser, visit/contact click counts, and member totals.
- `/affiliate/profile`: public display name, LINE add-friend URL, introduction,
  headline, and explicit publish/hide setting. Personal LINE accounts should use
  the copied add-friend URL; OA accounts may also use `@LINE_ID`.
- `/p/{referral_code}`: public promotion page. Visiting it establishes the same
  30-day referral attribution used by `/r/{referral_code}` (direct registration).
  Non-published profiles show a generic page without private account names or LINE.
- The shared Contact menu goes through `/contact-us/{referral_code}` whenever
  a member context exists. It redirects only to that active member's published,
  validated LINE URL. Missing or hidden profiles show a fallback page rather than
  routing to another member. `/contact-company` retains the original central page.
- Explicit link visits replace prior attribution. The session and an opaque,
  HttpOnly `sn_affiliate` cookie retain the contact owner for 30 days while browsing
  the site and after registration/login. Signup still consumes `sn_ref`; the
  contact context remains independent. The authenticated user is used as contact
  owner only when no attribution exists. Profile changes apply immediately.
- Accepted destinations are HTTPS `line.me/(R/)ti/p/...` and `lin.ee/...` URLs.
  There is no LINE account authorization or message-reading access involved.
  Configure a real working add-friend URL; syntax checks do not verify ownership.
- `/affiliate/network`: own descendants up to 30 levels, 50 per page, with name/code
  search, direct-only filtering and private follow-up stages/notes. Members cannot
  edit other networks or change sponsors through these forms.
- `/admin/affiliates?edit={user_id}`: staff network inspection. Administrators can
  update member public profiles; managers can inspect networks but cannot update
  other members' profiles. No commissions, ranks, income or sponsor reassignment
  have been introduced.
- Public referral visits are counted once per member per session/30-minute window.
  Contact counts measure redirect clicks, not confirmed chats or conversions.
  These counts store no visitor IP, email or contact details. Do not cache public
  HTML across members: the controllers explicitly send `Cache-Control: no-store`.
- Run `php spark platform:install` for the additive schema upgrade. Existing users,
  credentials, referral codes and sponsors are preserved; new public profiles are
  hidden until configured. Current localhost URLs only work on this computer.
  Set `app.baseURL` to a reachable deployed domain before sharing publicly.

Validation:
`php -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --no-coverage`
and `python tools/run-isolated-smoke.py smoke_affiliate.py`.
The latter clones into the marked smoke DB, creates test credentials only there,
starts and stops its own port-8081 process, and checks redirect headers without
making any network requests to LINE. Run individual smoke workflows separately
when they expect a pristine database.

LINE URL scheme contract: https://developers.line.biz/en/docs/messaging-api/using-line-url-scheme/
