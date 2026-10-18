<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Support\Files;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Support\Arr;

final class CodeCandidates
{
    private const int MAX_HITS = 200;

    private const array PHP = ['php'];

    private const array VIEWS_AND_JS = ['php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'svelte'];

    private const array BACKEND_DIRS = ['app', 'routes', 'database', 'bootstrap'];

    /**
     * pattern => checks it feeds, the regex, where to look, which files, and
     * an optional second regex the same line must match.
     *
     * @var array<string, array{checks: list<string>, regex: string, dirs: list<string>, extensions: list<string>, and?: string}>
     */
    private const array PATTERNS = [
        'env() outside config' => ['checks' => ['env-outside-config'], 'regex' => '/(?<![\w>:$])env\(/', 'dirs' => ['app', 'routes', 'database', 'resources/views'], 'extensions' => self::PHP],
        'inline validation' => ['checks' => ['inline-validation'], 'regex' => '/\$request->validate\(|Validator::make\(|\$this->validate\(/', 'dirs' => ['app/Http/Controllers'], 'extensions' => self::PHP],
        'request->all()' => ['checks' => ['mass-assignment'], 'regex' => '/(\$request|request\(\))->(all|input)\(\)/', 'dirs' => ['app'], 'extensions' => self::PHP],
        'raw SQL' => ['checks' => ['sql-injection'], 'regex' => '/DB::(raw|select|statement|unprepared)\(|\b(where|orWhere|having|orderBy|select|groupBy)Raw\(/', 'dirs' => self::BACKEND_DIRS, 'extensions' => self::PHP],
        'shell or process' => ['checks' => ['command-injection'], 'regex' => '/(?<![\w>:$])(exec|shell_exec|system|passthru|proc_open|popen)\(|Process::|new Process\(/', 'dirs' => self::BACKEND_DIRS, 'extensions' => self::PHP],
        'unserialize' => ['checks' => ['unsafe-deserialization'], 'regex' => '/(?<![\w>:$])unserialize\(/', 'dirs' => self::BACKEND_DIRS, 'extensions' => self::PHP],
        'outgoing HTTP' => ['checks' => ['ssrf'], 'regex' => '/Http::|curl_init\(|new \\\\?GuzzleHttp\\\\Client\(|file_get_contents\(\s*\$/', 'dirs' => ['app'], 'extensions' => self::PHP],
        'file access with request data' => ['checks' => ['path-traversal', 'file-upload'], 'regex' => '/Storage::|->download\(|->file\(|fopen\(|file_get_contents\(|unlink\(/', 'dirs' => ['app', 'routes'], 'extensions' => self::PHP, 'and' => '/\$request|request\(/'],
        'broad catch' => ['checks' => ['swallowed-exception'], 'regex' => '/catch\s*\(\s*\\\\?(Throwable|Exception)\b/', 'dirs' => self::BACKEND_DIRS, 'extensions' => self::PHP],
        'weak hash or random' => ['checks' => ['weak-crypto'], 'regex' => '/(?<![\w>:$])(md5|sha1|crc32|rand|mt_rand|uniqid)\(/', 'dirs' => ['app'], 'extensions' => self::PHP],
        'unescaped output' => ['checks' => ['xss'], 'regex' => '/\{!!|v-html|x-html|\.innerHTML\s*=|dangerouslySetInnerHTML/', 'dirs' => ['resources/views', 'resources/js'], 'extensions' => self::VIEWS_AND_JS],
        'redirect to request data' => ['checks' => ['open-redirect'], 'regex' => '/redirect\(\)->(to|away)\(|->away\(/', 'dirs' => ['app', 'routes'], 'extensions' => self::PHP, 'and' => '/\$request|request\(/'],
        'stored upload' => ['checks' => ['file-upload'], 'regex' => '/->(store|storeAs|storePublicly|storePubliclyAs|move)\(/', 'dirs' => ['app'], 'extensions' => self::PHP],
    ];

    /**
     * @return list<array{pattern: string, checks: list<string>, total: int, hits: list<array{file: string, line: int, code: string}>}>
     */
    public function find(ProjectInspector $project): array
    {
        $contents = [];
        $result = [];

        foreach (self::PATTERNS as $name => $pattern) {
            $hits = [];
            $total = 0;

            foreach ($this->files($project, Arr::get($pattern, 'dirs'), Arr::get($pattern, 'extensions')) as $file) {
                $lines = $contents[$file] ??= preg_split('/\R/', (string) file_get_contents($project->path($file))) ?: [];

                foreach ($lines as $number => $line) {
                    if (preg_match(Arr::get($pattern, 'regex'), $line) !== 1 || (Arr::has($pattern, 'and') && preg_match(Arr::get($pattern, 'and'), $line) !== 1) || $this->isComment($line)) {
                        continue;
                    }

                    $total++;

                    if (count($hits) < self::MAX_HITS) {
                        $hits[] = ['file' => $file, 'line' => $number + 1, 'code' => mb_strimwidth(trim($line), 0, 140, '…')];
                    }
                }
            }

            $result[] = ['pattern' => $name, 'checks' => Arr::get($pattern, 'checks'), 'total' => $total, 'hits' => $hits];
        }

        return $result;
    }

    /**
     * @param  list<string>  $dirs
     * @param  list<string>  $extensions
     * @return list<string> relative paths
     */
    private function files(ProjectInspector $project, array $dirs, array $extensions): array
    {
        $files = [];

        foreach ($dirs as $dir) {
            foreach (Files::below($project->path($dir)) as $file) {
                $relative = $project->relative($file->getPathname());

                if (in_array(strtolower($file->getExtension()), $extensions, true) && ! str_starts_with($relative, 'bootstrap/cache/')) {
                    $files[] = $relative;
                }
            }
        }

        sort($files);

        return $files;
    }

    private function isComment(string $line): bool
    {
        return preg_match('~^\s*(//|#|\*|/\*|\{\{--)~', $line) === 1;
    }
}
