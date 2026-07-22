# V1 P1 status

P1 requirements from [grandpasson-spec-v1-extension.md](grandpasson-spec-v1-extension.md):

| Req | Status | Evidence |
|-----|--------|----------|
| R10 PATs | Shipped | `pat:*` CLI; migration `014`; introspect `token_use=pat` |
| R11 Auth-code + PKCE | **Deferred** | Tracked as #45 (Q3: session-exchange assumed enough) |
| R12 Admin HTTP UI | Shipped | `/admin`, `/admin/api` + `ADMIN_API_TOKEN` |
| R13 DB oauth rate limits | Shipped | `DbRateLimiter`; migration `015` |

Do not start P2 (#25) until product schedules reader sessions / JWT work.
