Pick project skills for audit tasks. A task gets a skill's text in its prompt.

## Tasks

{{tasks}}

## Skills

{{skills}}

## Rules

- A skill fits a task when it covers what the task checks (a models skill for model hygiene, a PHPStan skill for the PHPStan task).
- At most {{per_task}} skills per task. Most tasks need none: leave them out.
- Only the task keys and skill names above. No tools.

Answer with one JSON object only, e.g. {"backend.data": ["models"]}.
