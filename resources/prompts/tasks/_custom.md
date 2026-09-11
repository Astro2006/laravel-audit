# Task: Custom checks

## Mission

Check the project-specific rules below. Each rule is a convention the project owner wants enforced in the area of the {{audit_title}}.

## Rules to check

{{custom_checks}}

## How

- For every rule, find the places where it applies and whether the code follows it.
- Report each violated rule as one grouped finding with check `custom`, all affected locations and an `Occurrences` count. Name the rule in the title.
- A rule that is followed everywhere is not a finding; mention it under `## Positive observations`.
