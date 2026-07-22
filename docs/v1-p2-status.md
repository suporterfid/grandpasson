# V1 P2 status

| Req | Status | Evidence |
|-----|--------|----------|
| R14 Reader sessions | Shipped | `published_sites`, `reader_sessions`, `/site/{id}/…`, cookie `GPSREADER` |
| R15 Optional JWT | Shipped | `JwtAccessTokenFactory` HS256 companion field `jwt` on `/oauth/token` when enabled |
| R16 Key rotation | Open (#49); only if RS256/JWKS is scheduled | — |
