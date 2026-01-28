<?php

namespace App\Services;

class NoteService
{
    /**
     * Get the path to state.json file
     */
    public function getStateFilePath(): string
    {
        $stateDir = base_path('storage');
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0755, true);
        }
        return $stateDir . DIRECTORY_SEPARATOR . 'state.json';
    }

    /**
     * Load search results from state.json
     * @return array<string, array<string, mixed>>
     */
    public function loadSearchResults(): array
    {
        $stateFile = $this->getStateFilePath();
        if (!file_exists($stateFile)) {
            return [];
        }
        $content = @file_get_contents($stateFile);
        if ($content === false) {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Get note data by index from state.json
     * @return array<string, mixed>|null
     */
    public function getNoteByIndex(string $index): ?array
    {
        $results = $this->loadSearchResults();
        return $results[$index] ?? null;
    }

    /**
     * Parse frontmatter from note content
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function parseFrontmatter(string $content): array
    {
        $frontmatter = [];
        if (preg_match('/^\s*---\s*\n(.*?)\n---\s*\n?/s', $content, $m)) {
            $body = substr($content, strlen($m[0]));
            $lines = explode("\n", $m[1]);
            $key = null;
            $block = '';
            foreach ($lines as $line) {
                if (preg_match('/^([a-zA-Z0-9_-]+)\s*:\s*(.*)$/', $line, $mm)) {
                    if ($key !== null) {
                        $frontmatter[$key] = $this->parseFrontmatterValue($block);
                    }
                    $key = $mm[1];
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
        // Multi-line YAML list
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

    /**
     * Build frontmatter YAML string from array
     */
    public function buildFrontmatter(array $frontmatter): string
    {
        if (empty($frontmatter)) {
            return '';
        }

        $lines = ["---"];
        foreach ($frontmatter as $key => $value) {
            if (is_array($value)) {
                if (empty($value)) {
                    $lines[] = "$key: []";
                } else {
                    $lines[] = "$key:";
                    foreach ($value as $item) {
                        $lines[] = "  - " . $this->escapeYamlValue($item);
                    }
                }
            } elseif (is_bool($value)) {
                $lines[] = "$key: " . ($value ? 'true' : 'false');
            } else {
                $lines[] = "$key: " . $this->escapeYamlValue($value);
            }
        }
        $lines[] = "---";
        return implode("\n", $lines) . "\n";
    }

    private function escapeYamlValue(mixed $value): string
    {
        $str = (string) $value;
        // If value contains special characters, quote it
        if (preg_match('/[:\[\]{}|>&*!@#%`]/', $str) || str_contains($str, "\n")) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $str) . '"';
        }
        return $str;
    }

    /**
     * Generate slug from title
     */
    public function generateSlug(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

    /**
     * Find available filename by incrementing if exists
     */
    public function findAvailableFilename(string $vaultPath, string $path, string $slug): string
    {
        $basePath = rtrim($vaultPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        $dir = dirname($basePath);
        if ($dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fullPath = $basePath . DIRECTORY_SEPARATOR . $slug . '.md';
        if (!file_exists($fullPath)) {
            return $slug;
        }

        $counter = 1;
        while (file_exists($basePath . DIRECTORY_SEPARATOR . $slug . '-' . $counter . '.md')) {
            $counter++;
        }
        return $slug . '-' . $counter;
    }

    /**
     * Get vault path from environment
     */
    public function getVaultPath(): string
    {
        $vaultPath = rtrim((string) env('VAULT_PATH'), DIRECTORY_SEPARATOR);
        if ($vaultPath === '' || !is_dir($vaultPath)) {
            throw new \RuntimeException('VAULT_PATH is not set or not a directory. Set it in .env.');
        }
        return $vaultPath;
    }
}
