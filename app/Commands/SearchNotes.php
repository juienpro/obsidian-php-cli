<?php

namespace App\Commands;

use App\Models\SearchItem;
use App\Services\NoteService;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Helper\Table;

class SearchNotes extends Command
{
    protected $signature = 'note:search
        {--operator=AND : Combine criteria with AND or OR}
        {--path=* : Relative path(s) from vault root (exact or prefix)}
        {--pathContains=* : String(s) that relative path must contain (case insensitive)}
        {--withoutPathContains=* : Exclude notes whose relative path contains any of these strings (case insensitive)}
        {--tag=* : Frontmatter tag(s) (note must have at least one)}
        {--property=* : Frontmatter property name(s) that must exist}
        {--propertyValue=* : property_name,property_value pairs}
        {--withoutTag=* : Exclude notes that have any of these frontmatter tags}
        {--withoutProperty=* : Exclude notes that have any of these frontmatter property names}
        {--withoutPropertyValue=* : Exclude notes with these property_name,property_value pairs}
        {--title=* : Title(s) to match (frontmatter title or first heading)}
        {--content=* : Content substring(s) to match in body}
        {--modifiedBefore= : Filter by last modification date before (format: YYYY-MM-DD)}
        {--modifiedAfter= : Filter by last modification date after (format: YYYY-MM-DD)}
        {--last= : Show only the last N modified notes from search results}
        {--j : Output results in JSON format (includes matchedParameters)}
    ';

    protected $description = 'Search notes in the Obsidian vault (AND/OR logic, YAML frontmatter, path filters)';

    public function handle(NoteService $noteService): int
    {
        $vaultPath = rtrim((string) env('VAULT_PATH'), DIRECTORY_SEPARATOR);
        if ($vaultPath === '' || ! is_dir($vaultPath)) {
            $this->error('VAULT_PATH is not set or not a directory. Set it in .env.');
            return self::FAILURE;
        }

        $operator = strtoupper((string) $this->option('operator'));
        if ($operator !== 'AND' && $operator !== 'OR') {
            $operator = 'AND';
        }

        $criteria = $this->collectCriteria();
        if ($criteria === []) {
            $this->warn('No search criteria provided. Listing all .md notes in vault.');
        }

        $files = $this->listMarkdownFiles($vaultPath);
        $results = [];
        $index = 0;

        foreach ($files as $fullPath) {
            $relativePath = substr($fullPath, strlen($vaultPath) + 1);
            $relativePath = str_replace('\\', '/', $relativePath);
            $content = @file_get_contents($fullPath);
            if ($content === false) {
                continue;
            }
            [$frontmatter, $body] = $this->parseFrontmatter($content);
            $title = $this->titleFromPath($relativePath);
            $lastModificationDate = date('Y-m-d', filemtime($fullPath));

            $matched = $this->evaluateCriteria($operator, $criteria, [
                'relativePath' => $relativePath,
                'fullPath' => $fullPath,
                'frontmatter' => $frontmatter,
                'body' => $body,
                'title' => $title,
            ]);

            if ($matched !== null) {
                $results[] = new SearchItem($index++, $fullPath, $relativePath, $title, $lastModificationDate, $matched, $frontmatter);
            }
        }

        // Apply modification date filters
        $results = $this->filterByModificationDate($results);

        // Apply --last filter
        $last = $this->option('last');
        if ($last !== null && is_numeric($last)) {
            $results = $this->filterLastN($results, (int) $last);
        }

        // Store results in state.json
        $this->storeSearchResults($noteService, $results);

        // Output results
        if ($this->option('j')) {
            $this->line(json_encode(array_map(static fn(SearchItem $s) => $s->toArray(true), $results), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->displayTable($results, $noteService);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function collectCriteria(): array
    {
        $criteria = [];
        $optionToKey = [
            'path' => 'path',
            'pathContains' => 'pathContains',
            'tag' => 'tags',
            'property' => 'properties',
            'title' => 'title',
            'content' => 'content',
            'withoutTag' => 'withoutTags',
            'withoutProperty' => 'withoutProperties',
            'withoutPathContains' => 'withoutPathContains',
        ];
        foreach ($optionToKey as $option => $key) {
            $values = $this->option($option);
            if (is_array($values) && $values !== []) {
                $criteria[$key] = array_values(array_filter(array_map('strval', $values)));
            }
        }
        $pv = $this->option('propertyValue');
        if (is_array($pv) && $pv !== []) {
            foreach ($pv as $raw) {
                $parts = explode(',', (string) $raw, 2);
                if (count($parts) === 2) {
                    $criteria['propertyValue'][] = ['name' => trim($parts[0]), 'value' => trim($parts[1])];
                }
            }
        }
        if (isset($criteria['propertyValue'])) {
            $criteria['propertyValue'] = array_values($criteria['propertyValue']);
        }
        $wpv = $this->option('withoutPropertyValue');
        if (is_array($wpv) && $wpv !== []) {
            foreach ($wpv as $raw) {
                $parts = explode(',', (string) $raw, 2);
                if (count($parts) === 2) {
                    $criteria['withoutPropertyValue'][] = ['name' => trim($parts[0]), 'value' => trim($parts[1])];
                }
            }
        }
        if (isset($criteria['withoutPropertyValue'])) {
            $criteria['withoutPropertyValue'] = array_values($criteria['withoutPropertyValue']);
        }
        return $criteria;
    }

    /**
     * @return list<string>
     */
    private function listMarkdownFiles(string $vaultPath): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vaultPath, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                $out[] = $file->getRealPath();
            }
        }
        return $out;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function parseFrontmatter(string $content): array
    {
        $frontmatter = [];
        if (preg_match('/^\s*---\s*\n(.*?)\n---\s*\n?/s', $content, $m)) {
            $body = substr($content, strlen($m[0]));
            $lines = explode("\n", $m[1]);
            $key = null;
            $block = '';
            foreach ($lines as $line) {
                if (preg_match('/^([a-zA-Z0-9_\-\s]+?)\s*:\s*(.*)$/', $line, $mm)) {
                    if ($key !== null) {
                        $frontmatter[$key] = $this->parseFrontmatterValue($block);
                    }
                    $key = trim($mm[1]);
                    $block = trim($mm[2]);
                } elseif ($key !== null && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
                    $block .= "\n" . $line;
                } elseif ($key !== null) {
                    $frontmatter[$key] = $this->parseFrontmatterValue($block);
                    $key = null;
                    $block = '';
                }
            }
            if ($key !== null) {
                $frontmatter[$key] = $this->parseFrontmatterValue($block);
            }
        } else {
            $body = $content;
        }
        return [$frontmatter, $body];
    }

    private function parseFrontmatterValue(string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === 'true') {
            return true;
        }
        if ($raw === 'false') {
            return false;
        }
        // Inline array: [a, b, c]
        if (preg_match('/^\[(.*)\]$/s', $raw, $m)) {
            $inner = trim($m[1]);
            if ($inner === '') {
                return [];
            }
            $items = [];
            foreach (array_map('trim', explode(',', $inner)) as $item) {
                $items[] = trim($item, " \t\n\r\0\x0B\"'");
            }
            return $items;
        }
        // Multi-line YAML list (e.g. "  - Object/Note\n  - other")
        $lines = preg_split('/\r?\n/', $raw);
        $list = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*-\s+(.*)$/', $line, $mm)) {
                $list[] = trim($mm[1], " \t\n\r\0\x0B\"'");
            }
        }
        if ($list !== []) {
            return $list;
        }
        return trim($raw, "\"'");
    }

    private function titleFromPath(string $relativePath): string
    {
        // Extract the last part of the path without .md extension
        $basename = basename($relativePath);
        return preg_replace('/\.md$/i', '', $basename);
    }

    /**
     * @param array<string, array<int, mixed>> $criteria
     * @param array{relativePath: string, fullPath: string, frontmatter: array, body: string, title: string} $ctx
     * @return array<string, mixed>|null Matched parameters for SearchItem, or null if no match
     */
    private function evaluateCriteria(string $operator, array $criteria, array $ctx): ?array
    {
        if ($criteria === []) {
            return ['path' => $ctx['relativePath']];
        }

        $matched = [];

        $pathMatch = $this->matchPath($criteria['path'] ?? [], $ctx['relativePath']);
        if ($pathMatch !== null) {
            $matched['path'] = $pathMatch;
        }
        $pathContainsMatch = $this->matchPathContains($criteria['pathContains'] ?? [], $ctx['relativePath']);
        if ($pathContainsMatch !== null) {
            $matched['pathContains'] = $pathContainsMatch;
        }
        $tagsMatch = $this->matchTags($criteria['tags'] ?? [], $ctx['frontmatter']);
        if ($tagsMatch !== null) {
            $matched['tags'] = $tagsMatch;
        }
        $propertiesMatch = $this->matchProperties($criteria['properties'] ?? [], $ctx['frontmatter']);
        if ($propertiesMatch !== null) {
            $matched['properties'] = $propertiesMatch;
        }
        $pvMatch = $this->matchPropertyValue($criteria['propertyValue'] ?? [], $ctx['frontmatter']);
        if ($pvMatch !== null) {
            $matched['propertyValue'] = $pvMatch;
        }
        $titleMatch = $this->matchTitle($criteria['title'] ?? [], $ctx['title']);
        if ($titleMatch !== null) {
            $matched['title'] = $titleMatch;
        }
        $contentMatch = $this->matchContent($criteria['content'] ?? [], $ctx['body']);
        if ($contentMatch !== null) {
            $matched['content'] = $contentMatch;
        }

        // Exclusions: note must NOT have any of these
        if ($this->matchPathContains($criteria['withoutPathContains'] ?? [], $ctx['relativePath']) !== null) {
            return null;
        }
        if ($this->noteHasAnyOfTheseTags($criteria['withoutTags'] ?? [], $ctx['frontmatter'])) {
            return null;
        }
        if ($this->noteHasAnyOfTheseProperties($criteria['withoutProperties'] ?? [], $ctx['frontmatter'])) {
            return null;
        }
        if ($this->noteHasAnyOfThesePropertyValues($criteria['withoutPropertyValue'] ?? [], $ctx['frontmatter'])) {
            return null;
        }

        $exclusionKeys = ['withoutPathContains', 'withoutTags', 'withoutProperties', 'withoutPropertyValue'];
        $positiveKeys = array_diff(array_keys($criteria), $exclusionKeys);
        if ($positiveKeys === [] && $criteria !== []) {
            return ['path' => $ctx['relativePath']];
        }

        if ($operator === 'OR') {
            return $matched !== [] ? $matched : null;
        }

        $required = $positiveKeys;
        foreach ($required as $key) {
            if ($key === 'propertyValue') {
                if (empty($matched['propertyValue'])) {
                    return null;
                }
                continue;
            }
            if (! array_key_exists($key, $matched)) {
                return null;
            }
        }
        return $matched;
    }

    /**
     * @param list<string> $values
     */
    private function matchPath(array $values, string $relativePath): ?string
    {
        if ($values === []) {
            return null;
        }
        $normal = str_replace('\\', '/', $relativePath);
        foreach ($values as $v) {
            $v = str_replace('\\', '/', $v);
            if ($normal === $v || str_starts_with($normal . '/', $v . '/')) {
                return $v;
            }
        }
        return null;
    }

    /**
     * @param list<string> $values
     */
    private function matchPathContains(array $values, string $relativePath): ?array
    {
        if ($values === []) {
            return null;
        }
        $lower = strtolower($relativePath);
        $found = [];
        foreach ($values as $v) {
            if (str_contains($lower, strtolower($v))) {
                $found[] = $v;
            }
        }
        return $found === [] ? null : $found;
    }

    /**
     * @param list<string> $values
     * @param array<string, mixed> $frontmatter
     */
    private function matchTags(array $values, array $frontmatter): ?array
    {
        if ($values === []) {
            return null;
        }
        $tags = [];
        if (isset($frontmatter['tags'])) {
            $t = $frontmatter['tags'];
            $tags = is_array($t) ? $t : [$t];
        }
        $tags = array_map('strval', $tags);
        $normalizeTag = static fn(string $s): string => ltrim(trim($s), '#');
        $tagsNormalized = array_map($normalizeTag, $tags);
        $found = [];
        foreach ($values as $v) {
            $vNorm = $normalizeTag($v);
            if (in_array($vNorm, $tagsNormalized, true)) {
                $found[] = $v;
            }
        }
        return $found === [] ? null : $found;
    }

    /**
     * @param list<string> $names
     * @param array<string, mixed> $frontmatter
     */
    private function matchProperties(array $names, array $frontmatter): ?array
    {
        if ($names === []) {
            return null;
        }
        $found = [];
        foreach ($names as $name) {
            if (array_key_exists($name, $frontmatter)) {
                $found[] = $name;
            }
        }
        return $found === [] ? null : $found;
    }

    /**
     * @param list<array{name: string, value: string}> $pairs
     * @param array<string, mixed> $frontmatter
     */
    private function matchPropertyValue(array $pairs, array $frontmatter): ?array
    {
        if ($pairs === []) {
            return null;
        }
        $found = [];
        foreach ($pairs as $p) {
            $name = $p['name'];
            $want = $p['value'];
            if (! array_key_exists($name, $frontmatter)) {
                continue;
            }
            $actual = $frontmatter[$name];
            if (is_array($actual)) {
                if (in_array($want, $actual, true)) {
                    $found[] = $name . '=' . $want;
                }
            } else {
                $normalized = is_bool($actual) ? ($actual ? 'true' : 'false') : (string) $actual;
                if ($normalized === $want) {
                    $found[] = $name . '=' . $want;
                }
            }
        }
        return $found === [] ? null : $found;
    }

    /**
     * @param list<string> $values
     * @param array<string, mixed> $frontmatter
     */
    private function noteHasAnyOfTheseTags(array $values, array $frontmatter): bool
    {
        if ($values === []) {
            return false;
        }
        return $this->matchTags($values, $frontmatter) !== null;
    }

    /**
     * @param list<string> $names
     * @param array<string, mixed> $frontmatter
     */
    private function noteHasAnyOfTheseProperties(array $names, array $frontmatter): bool
    {
        if ($names === []) {
            return false;
        }
        return $this->matchProperties($names, $frontmatter) !== null;
    }

    /**
     * @param list<array{name: string, value: string}> $pairs
     * @param array<string, mixed> $frontmatter
     */
    private function noteHasAnyOfThesePropertyValues(array $pairs, array $frontmatter): bool
    {
        if ($pairs === []) {
            return false;
        }
        return $this->matchPropertyValue($pairs, $frontmatter) !== null;
    }

    /**
     * @param list<string> $values
     */
    private function matchTitle(array $values, string $title): ?array
    {
        if ($values === [] || $title === '') {
            return $values === [] ? null : null;
        }
        $found = [];
        foreach ($values as $v) {
            if (stripos($title, $v) !== false) {
                $found[] = $v;
            }
        }
        return $found === [] ? null : $found;
    }

    /**
     * @param list<string> $values
     */
    private function matchContent(array $values, string $body): ?array
    {
        if ($values === []) {
            return null;
        }
        $found = [];
        foreach ($values as $v) {
            if (stripos($body, $v) !== false) {
                $found[] = $v;
            }
        }
        return $found === [] ? null : $found;
    }

    /**
     * Filter results by modification date
     * @param array<SearchItem> $results
     * @return array<SearchItem>
     */
    private function filterByModificationDate(array $results): array
    {
        $modifiedBefore = $this->option('modifiedBefore');
        $modifiedAfter = $this->option('modifiedAfter');

        if ($modifiedBefore === null && $modifiedAfter === null) {
            return $results;
        }

        $filtered = [];
        foreach ($results as $item) {
            if ($item->lastModificationDate === null) {
                continue;
            }

            $itemDate = $item->lastModificationDate;
            $beforeOk = true;
            $afterOk = true;

            if ($modifiedBefore !== null) {
                $beforeOk = $itemDate <= $modifiedBefore;
            }
            if ($modifiedAfter !== null) {
                $afterOk = $itemDate >= $modifiedAfter;
            }

            if ($beforeOk && $afterOk) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    /**
     * Filter to show only the last N modified notes
     * @param array<SearchItem> $results
     * @return array<SearchItem>
     */
    private function filterLastN(array $results, int $n): array
    {
        if ($n <= 0 || empty($results)) {
            return [];
        }

        // Sort by lastModificationDate descending
        usort($results, function (SearchItem $a, SearchItem $b) {
            $dateA = $a->lastModificationDate ?? '1970-01-01';
            $dateB = $b->lastModificationDate ?? '1970-01-01';
            return strcmp($dateB, $dateA); // Descending order
        });

        return array_slice($results, 0, $n);
    }

    /**
     * Store search results in state.json
     * @param array<SearchItem> $results
     */
    private function storeSearchResults(NoteService $noteService, array $results): void
    {
        $stateFile = $noteService->getStateFilePath();
        $stateData = [];
        foreach ($results as $item) {
            $stateData[(string) $item->index] = $item->toArray();
        }
        file_put_contents($stateFile, json_encode($stateData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /**
     * Display results in a formatted table
     * @param array<SearchItem> $results
     */
    private function displayTable(array $results, NoteService $noteService): void
    {
        if (empty($results)) {
            $this->info('No results found.');
            return;
        }

        $table = new Table($this->output);
        $table->setHeaders(['ID', 'Path', 'Title', 'Last Modified']);

        foreach ($results as $item) {
            $table->addRow([
                $item->index,
                $item->relativePath,
                $item->title ?: '(no title)',
                $item->lastModificationDate ?? 'N/A',
            ]);
        }

        $table->render();
    }

    public function schedule(Schedule $schedule): void {}
}
