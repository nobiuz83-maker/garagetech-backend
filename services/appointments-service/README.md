# Appointments Service

Independent Laravel application with its own database and Docker image. Stage 2B adds an AuthService client that validates the opaque browser session over mTLS and a service-specific HMAC key. The route `/api/internal/identity-check` exercises the read/write contract in the isolated Docker network. No business records or routes have been extracted yet.

The service never reads `auth_db` or decrypts the Auth session cookie. Missing cookies return 401; Auth permission denials return 403; missing write CSRF returns 419; timeouts, connection failures, malformed Auth replies, and Auth server failures return 503. No positive identity result is cached. Future business routes must select a fixed permission in route middleware and apply their own ownership policy.
