# Task: Authorization, auth and secrets

## Mission

Check authorization inside the application logic, the authentication configuration, cryptography and secrets.

## Inputs

- `{{preflight_dir}}/routes.json` — entry points and middleware (web, API, MCP)
- `app/Policies`, gates in service providers, actions/services called from several entry points
- `config/auth.php`, `config/session.php`, `config/sanctum.php`, profile/password controllers, `.env.example`

## Checklist

- **Authorization gaps** in actions or services invoked from several entry points (web, API, jobs, commands, MCP tools) where only some check permissions; tokens without abilities that unlock more than intended. Route-level middleware as such belongs to the frontend audit. (`authorization-gap`)
- **Auth config:** password rehashing, session invalidation on password change (`logoutOtherDevices()`, `auth.session`), password rules (`Password::defaults()`). Verify framework behaviour before claiming a gap. (`auth-config`)
- **Multi-tenancy:** global scopes that can be bypassed, queries missing the tenant constraint, soft-deleted data leaking. Skip if the app has no tenants. (`tenant-isolation`)
- **Crypto & tokens:** `md5` / `sha1` for security purposes, homemade encryption, `rand()` / `mt_rand()` / `uniqid()` for tokens, secret comparison with `==` instead of `hash_equals()`. (`weak-crypto`)
- **Secrets:** hardcoded keys or tokens in code or config defaults, real-looking secrets in `.env.example`, `APP_DEBUG` defaulting to true. Mask any value you quote. (`hardcoded-secret`)
