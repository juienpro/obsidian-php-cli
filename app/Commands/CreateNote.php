<?php

namespace App\Commands;

use App\Services\NoteService;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class CreateNote extends Command
{
    protected $signature = 'note:create
        {path : Relative path to the note}
        {title : Title of the note}
        {--tags=* : Tags to apply}
        {--propertyValue=* : property,value pairs (if same property has multiple values, it becomes a list)}
        {--content= : Content for the note body}
    ';

    protected $description = 'Create a new note according to a template';

    public function handle(NoteService $noteService): int
    {
        try {
            $vaultPath = $noteService->getVaultPath();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $path = $this->argument('path');
        $title = $this->argument('title');

        // Find available filename using title directly
        $filename = $this->findAvailableFilename($vaultPath, $path, $title);
        $fullPath = rtrim($vaultPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            ltrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            $filename . '.md';

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Build frontmatter
        $frontmatter = ['title' => $title];

        // Handle tags
        $tags = $this->option('tags');
        if (is_array($tags) && !empty($tags)) {
            $frontmatter['tags'] = array_map(fn($tag) => ltrim(trim($tag), '#'), $tags);
        }

        // Handle propertyValue
        $propertyValues = $this->option('propertyValue');
        if (is_array($propertyValues) && !empty($propertyValues)) {
            foreach ($propertyValues as $pv) {
                $parts = explode(',', $pv, 2);
                if (count($parts) === 2) {
                    $propName = trim($parts[0]);
                    $propValue = trim($parts[1]);

                    // If property already exists, make it an array
                    if (isset($frontmatter[$propName])) {
                        if (!is_array($frontmatter[$propName])) {
                            $frontmatter[$propName] = [$frontmatter[$propName]];
                        }
                        if (!in_array($propValue, $frontmatter[$propName], true)) {
                            $frontmatter[$propName][] = $propValue;
                        }
                    } else {
                        $frontmatter[$propName] = $propValue;
                    }
                }
            }
        }

        // Handle content
        $content = $this->option('content') ?? '';

        // Build note content
        $frontmatterYaml = $noteService->buildFrontmatter($frontmatter);
        $noteContent = $frontmatterYaml . $content;

        // Write file
        if (file_put_contents($fullPath, $noteContent) === false) {
            $this->error("Failed to create note: {$fullPath}");
            return self::FAILURE;
        }

        $relativePath = substr($fullPath, strlen($vaultPath) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);
        $this->info("Note created: {$relativePath}");

        return self::SUCCESS;
    }

    /**
     * Find available filename by incrementing if exists
     */
    private function findAvailableFilename(string $vaultPath, string $path, string $title): string
    {
        $basePath = rtrim($vaultPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        $dir = dirname($basePath);
        if ($dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fullPath = $basePath . DIRECTORY_SEPARATOR . $title . '.md';
        if (!file_exists($fullPath)) {
            return $title;
        }

        $counter = 1;
        while (file_exists($basePath . DIRECTORY_SEPARATOR . $title . '-' . $counter . '.md')) {
            $counter++;
        }
        return $title . '-' . $counter;
    }

    public function schedule(Schedule $schedule): void {}
}
