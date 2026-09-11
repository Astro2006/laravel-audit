# Task: Headers, session and CORS

## Mission

Check the HTTP security configuration.

## Inputs

- `config/session.php`, `config/cors.php`, `bootstrap/app.php`, `app/Http/Middleware`, web server config in the repo if present

## Checklist

- Security headers: CSP, `X-Frame-Options` / `frame-ancestors`, `X-Content-Type-Options`, HSTS where HTTPS is used. (`security-headers`)
- Session cookies: `secure`, `http_only`, `same_site`, lifetime, driver. Read the defaults in `config/session.php`; you cannot see `.env`. (`session-cookie-config`)
- CORS: allowed origins, credentials, wildcard use. (`cors-config`)
