# Task: Error handling and logging

## Mission

Find exceptions that disappear, exception details that reach users, and sensitive data in logs.

## Inputs

- `bootstrap/app.php` (`withExceptions`), `app/Exceptions`, `config/logging.php`
- Search `app/` for `catch`, `report(`, `Log::`, `logger(`, `->getMessage()`

## Checklist

- Swallowed exceptions: `catch` without rethrow, `report()` or log; catching `\Throwable` / `\Exception` broadly around code that should fail loudly. A documented best-effort path is fine. (`swallowed-exception`)
- Exception messages or traces returned to users or API clients (`$e->getMessage()` in responses, flash messages or JSON). (`exception-leak`)
- Passwords, tokens, full request payloads or personal data written to logs. (`sensitive-logging`)
