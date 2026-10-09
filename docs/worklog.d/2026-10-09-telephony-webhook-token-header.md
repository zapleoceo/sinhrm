---
date: 2026-10-09
area: Channels
---
HRM-26: токен вебхука телефонии — заголовком `X-Webhook-Token` / `Authorization: Bearer` или подписью `X-Signature` (HMAC тела); `?token=` только за флагом `webhook_query_token` (новым — выкл., существующим — вкл. и «устарело» в «Інтеграціях»); неверный токен → 401 — [channels.md](modules/channels.md)
