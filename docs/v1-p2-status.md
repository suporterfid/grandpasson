# V1 P2 status

| Req | Status | Evidence |
|-----|--------|----------|
| R14 Reader sessions | Shipped | `published_sites`, `reader_sessions`, `/site/{id}/…`, cookie `GPSREADER` |
| R15 Optional JWT | Shipped | HS256 companion `jwt` via `JWT_HMAC_SECRET`, or RS256 when keys exist |
| R16 Key rotation | Shipped | `jwt_signing_keys`, `jwt:key-rotate|list|retire`, `GET /.well-known/jwks.json` |
