<?php

namespace App\Commands;

use App\Services\NoteService;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class CreateFromTemplate extends Command
{
    protected $signature = 'note:createFromTemplate
        {path : Relative path to the note}
        {title : Title of the note}
        {template : Path to the template file}
        {--replace=* : string_to_replace,value pairs}
        {--j : Output result in JSON format}
    ';

    protected $description = 'Create a new note from a template file with variable replacement';

    public function handle(NoteService $noteService): int
    {
        try {
            $vaultPath = $noteService->getVaultPath();
        } catch (\RuntimeException $e) {
            if ($this->option('j')) {
                $this->line(json_encode(['success' => false, 'error' => $e->getMessage()]));
            } else {
                $this->error($e->getMessage());
            }
            return self::FAILURE;
        }

        $path = $this->argument('path');
        $title = $this->argument('title');
        $templatePath = $this->argument('template');

        // Resolve template path (can be absolute or relative to vault)
        if (!file_exists($templatePath)) {
            $templatePath = rtrim($vaultPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($templatePath, DIRECTORY_SEPARATOR);
        }

        if (!file_exists($templatePath)) {
            $error = "Template file not found: {$templatePath}";
            if ($this->option('j')) {
                $this->line(json_encode(['success' => false, 'error' => $error]));
            } else {
                $this->error($error);
            }
            return self::FAILURE;
        }

        // Read template
        $templateContent = file_get_contents($templatePath);
        if ($templateContent === false) {
            $error = "Could not read template file: {$templatePath}";
            if ($this->option('j')) {
                $this->line(json_encode(['success' => false, 'error' => $error]));
            } else {
                $this->error($error);
            }
            return self::FAILURE;
        }

        // Parse template to get frontmatter and body
        [$frontmatter, $body] = $noteService->parseFrontmatter($templateContent);

        // Replace variables in frontmatter and body
        $replacements = $this->option('replace');
        if (is_array($replacements) && !empty($replacements)) {
            foreach ($replacements as $replacement) {
                $parts = explode(',', $replacement, 2);
                if (count($parts) === 2) {
                    $search = '{{' . trim($parts[0]) . '}}';
                    $replace = trim($parts[1]);

                    // Replace in frontmatter values
                    foreach ($frontmatter as $key => $value) {
                        if (is_string($value)) {
                            $frontmatter[$key] = str_replace($search, $replace, $value);
                        } elseif (is_array($value)) {
                            $frontmatter[$key] = array_map(
                                fn($item) => is_string($item) ? str_replace($search, $replace, $item) : $item,
                                $value
                            );
                        }
                    }

                    // Replace in body
                    $body = str_replace($search, $replace, $body);
                }
            }
        }

        // Update title if provided
        if ($title) {
            $frontmatter['title'] = $title;
        }

        // Generate slug from title
        $slug = $noteService->generateSlug($frontmatter['title'] ?? $title);

        // Find available filename
        $finalSlug = $noteService->findAvailableFilename($vaultPath, $path, $slug);
        $fullPath = rtrim($vaultPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            ltrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            $finalSlug . '.md';

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Build note content
        $frontmatterYaml = $noteService->buildFrontmatter($frontmatter);
        $noteContent = $frontmatterYaml . $body;

        // Write file
        if (file_put_contents($fullPath, $noteContent) === false) {
            $error = "Failed to create note: {$fullPath}";
            if ($this->option('j')) {
                $this->line(json_encode(['success' => false, 'error' => $error]));
            } else {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $relativePath = substr($fullPath, strlen($vaultPath) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);

        if ($this->option('j')) {
            $this->line(json_encode([
                'success' => true,
                'path' => $relativePath,
                'slug' => $finalSlug,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->info("Note created: {$relativePath}");
        }

        return self::SUCCESS;
    }

    public function schedule(Schedule $schedule): void {}
}
