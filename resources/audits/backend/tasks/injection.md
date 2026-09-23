# Task: Injection

## Mission

Find input that reaches a dangerous sink: SQL, shell, deserialization, outgoing HTTP requests or file paths.

## Inputs

- The Routes snapshot above and its `all-routes` file — entry points
- The Code candidates snapshot above — the sinks below with file and line (raw SQL, shell calls, `unserialize`, outgoing HTTP, file access with request data). Open those lines and follow the data back to its source; search yourself only for sinks the snapshot cannot see

## Checklist

- **SQL injection:** `DB::raw`, `whereRaw`, `selectRaw`, `orderByRaw`, `havingRaw`, `DB::statement`, `DB::select` with interpolated variables — including sort, order and filter parameters from list views. Bound parameters (`?`) are fine. (`sql-injection`)
- **Command injection:** `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, backticks, `Process::run()` with a string command containing input. (`command-injection`)
- **Unsafe deserialization:** `unserialize()` on untrusted data without `allowed_classes`. (`unsafe-deserialization`)
- **SSRF:** HTTP client calls with user-controlled URLs or hosts. (`ssrf`)
- **Path traversal:** `Storage`, `File`, `fopen`, `include` with user-influenced paths. (`path-traversal`)

Only report a sink when input can reach it; say where the input comes from.
