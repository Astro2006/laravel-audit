<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

/**
 * Groups PHPStan's JSON output by identifier and file, and adds the
 * baseline entries as "baselined" so hidden errors stay visible.
 */
final class PhpstanGrouper
{
    /**
     * @param  array<string, mixed>  $result  phpstan analyse --error-format=json
     * @param  list<array{identifier: string|null, message: string, count: int, path: string}>  $baseline
     * @return array<string, mixed>
     */
    public function group(array $result, array $baseline, string $basePath): array
    {
        $groups = [];
        $total = 0;

        foreach ((array) ($result['files'] ?? []) as $file => $data) {
            $relative = $this->relative((string) $file, $basePath);

            foreach ((array) ($data['messages'] ?? []) as $message) {
                $identifier = (string) ($message['identifier'] ?? 'unknown');
                $total++;

                $groups[$identifier] ??= ['identifier' => $identifier, 'count' => 0, 'files' => [], 'examples' => []];
                $groups[$identifier]['count']++;
                $groups[$identifier]['files'][$relative] = ($groups[$identifier]['files'][$relative] ?? 0) + 1;

                if (count($groups[$identifier]['examples']) < 3) {
                    $groups[$identifier]['examples'][] = [
                        'file' => $relative,
                        'line' => $message['line'] ?? null,
                        'message' => (string) ($message['message'] ?? ''),
                    ];
                }
            }
        }

        $baselined = [];
        $baselinedTotal = 0;

        foreach ($baseline as $entry) {
            $identifier = $entry['identifier'] ?? 'unknown';
            $count = max(1, $entry['count']);
            $baselinedTotal += $count;

            $baselined[$identifier] ??= ['identifier' => $identifier, 'count' => 0, 'files' => [], 'baselined' => true];
            $baselined[$identifier]['count'] += $count;
            $baselined[$identifier]['files'][$entry['path']] = ($baselined[$identifier]['files'][$entry['path']] ?? 0) + $count;
        }

        return [
            'errors' => $total,
            'baselined' => $baselinedTotal,
            'identifiers' => $this->finish($groups),
            'baselined_identifiers' => $this->finish($baselined),
            'general_errors' => array_values(array_map('strval', (array) ($result['errors'] ?? []))),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function finish(array $groups): array
    {
        foreach ($groups as &$group) {
            arsort($group['files']);
            $group['file_count'] = count($group['files']);
            $group['top_files'] = array_slice($group['files'], 0, 5, true);
            unset($group['files']);
        }

        unset($group);

        usort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $groups;
    }

    private function relative(string $file, string $basePath): string
    {
        $file = str_replace('\\', '/', $file);
        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
