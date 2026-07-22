# V1 P2 status

**Status:** P2 requirements R14–R16 are shipped on `main`.

| Req | Status | Evidence |
|-----|--------|----------|
| R14 Reader sessions | Shipped | `published_sites`, `reader_sessions`, `/site/{id}/login` chooser + provider routes, cookie `GPSREADER`; browser Accept → 302 |
| R15 Optional JWT | Shipped | Companion `jwt` on `/oauth/token` (HS256 env and/or RS256 keys) |
| R16 Key rotation | Shipped | `jwt:key-rotate|list|retire`, `GET /.well-known/jwks.json` |

Epic #25 is complete.
