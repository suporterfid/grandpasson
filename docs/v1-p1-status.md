# V1 P1 status

P1 requirements from [grandpasson-spec-v1-extension.md](grandpasson-spec-v1-extension.md):

| Req | Status | Evidence |
|-----|--------|----------|
| R10 PATs | Shipped | `pat:*` CLI + `/me/pats` self-service; migration `014`; introspect `token_use=pat` |
| R11 Auth-code + PKCE | Shipped | Public clients require S256 at login; `POST /oauth/token` `authorization_code`; migration `016` |
| R12 Admin HTTP UI | Shipped | `/admin`, `/admin/api` + `ADMIN_API_TOKEN` |
| R13 DB oauth rate limits | Shipped | `DbRateLimiter`; migration `015` |

Do not start P2 (#25) until product schedules reader sessions / JWT work (see child issues under that epic).
