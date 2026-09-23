# Laravel Audit — Pick project skills

Audit tasks will review this project. The project has skills installed: short documents with its conventions and rules. Pick the skills that help a task judge its checks, so the task gets them in its prompt.

## Tasks

{{tasks}}

## Skills

{{skills}}

## Rules

- A skill fits a task when its description covers what the task checks, for example a PHPStan skill for the PHPStan task or a models skill for model hygiene.
- At most {{per_task}} skills per task. Most tasks need none; leave them out.
- Use only the task keys and skill names listed above.
- Do not use tools. Answer right away.

Answer with one JSON object and nothing else, for example `{"backend.data": ["models"], "tests.phpstan": ["phpstan"]}`.
