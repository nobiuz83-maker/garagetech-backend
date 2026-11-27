# AuthService

Independent Laravel 13 identity authority for ViCarTech. It owns `auth_db`, the user records, browser sessions, CSRF state, and permission validation. It does not read another service's SQL tables.

Browser API: `GET /sanctum/csrf-cookie`, `POST /login`, `GET /api/user`, `POST /logout`. Existing source users retain their numeric identifiers and password hashes; clients and appointments retain their UUIDs in their own services.

Internal API: `GET /api/internal/v1/session/validate?permission=...` for reads and `POST /api/internal/v1/session/validate` for writes. The caller must use mutual TLS on port 8443, forward the opaque Auth session cookie, and sign the method, full request URI, timestamp, nonce, and SHA-256 request body with its own machine key. A valid machine signature alone never authenticates a user. Write validation also requires the browser's CSRF token. A missing or expired session returns 401, a denied permission returns 403, an invalid CSRF token returns 419, and an unavailable AuthService must be reported by a caller as 503.

The Gateway does not route to this service in stage 2A. Browser sessions from the legacy application are not imported; users will sign in again at a later approved cutover. No password reset or user deletion is part of this stage.
