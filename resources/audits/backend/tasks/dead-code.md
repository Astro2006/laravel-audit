# Task: Dead code

## Mission

Identify backend code that can be removed safely.

## Inputs

- The Routes snapshot above and its `all-routes` file — which controllers and methods are reachable
- The PHPStan snapshot above — unused private methods, unreachable code (supporting evidence only)

## Checklist

- Controllers or methods not reachable via any route, jobs never dispatched, events never fired, listeners not registered or discovered, commands never scheduled or called, mailables and notifications never sent, policies without a model mapping, services never resolved, enum cases never used, unused config keys, commented-out code blocks, stale feature flags. (`dead-code`)
- Old migrations are history, not dead code. Unused views and components belong to the frontend audit.
- **Work in few turns:** one search over all class names of a folder first (for example every `*Action` name across `app`, `routes`, `config` and `resources`), then open only the classes with a single hit. Searching class by class uses up the turn limit.
- **Verify before claiming:** `::class`, FQCN strings, container bindings, config arrays, event discovery, route definitions, Blade/Livewire references, queue or morph-map names. Set the confidence accordingly. Group the candidates into few findings.

## Section you write

- `## Dead code candidates` — table: Symbol · Type · Evidence of non-use · Confidence · Safe to delete (yes / after check / no).
