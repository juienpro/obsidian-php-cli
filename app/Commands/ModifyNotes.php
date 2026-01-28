<?php

namespace App\Commands;

use App\Services\NoteService;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class ModifyNotes extends Command
{
    protected $signature = 'note:modify
        {id* : The ID(s) of notes to modify (from latest search result)}
        {--propertyValue=* : property_name,value pairs}
        {--addTag=* : Tags to add}
        {--setTag= : Replace all tags with a single tag}
        {--removeTag=* : Tags to remove}
        {--content= : New content for the note body}
        {--j : Return result in JSON format}
    ';

    protected $description = 'Modify one or more notes by their ID from the latest search result';

    public function handle(NoteService $noteService): int
    {
        $ids = $this->argument('id');
        $results = $noteService->loadSearchResults();

        if (empty($results)) {
            if ($this->option('j')) {
                $this->line(json_encode(['success' => false, 'error' => 'No search results found. Run a search first.']));
            } else {
                $this->error('No search results found. Run a search first.');
            }
            return self::FAILURE;
        }

        $success = true;
        $errors = [];

        foreach ($ids as $id) {
            $noteData = $results[$id] ?? null;
            if ($noteData === null) {
                $errors[] = "Note with ID {$id} not found in search results.";
                $success = false;
                continue;
            }

            $fullPath = $noteData['fullPath'] ?? null;
            if ($fullPath === null || !file_exists($fullPath)) {
                $errors[] = "Note file not found for ID {$id}.";
                $success = false;
                continue;
            }

            try {
                $this->modifyNote($noteService, $fullPath);
            } catch (\Exception $e) {
                $errors[] = "Failed to modify note ID {$id}: " . $e->getMessage();
                $success = false;
            }
        }

        if ($this->option('j')) {
            $response = ['success' => $success];
            if (!empty($errors)) {
                $response['errors'] = $errors;
            }
            $this->line(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            if ($success) {
                $this->info('Success');
            } else {
                foreach ($errors as $error) {
                    $this->error($error);
                }
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function modifyNote(NoteService $noteService, string $fullPath): void
    {
        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new \RuntimeException("Could not read file: {$fullPath}");
        }

        [$frontmatter, $body] = $noteService->parseFrontmatter($content);

        // Handle propertyValue
        $propertyValues = $this->option('propertyValue');
        if (is_array($propertyValues) && !empty($propertyValues)) {
            foreach ($propertyValues as $pv) {
                $parts = explode(',', $pv, 2);
                if (count($parts) === 2) {
                    $propName = trim($parts[0]);
                    $rawValue = trim($parts[1]);

                    // Support list values separated by commas in the value part
                    $valueItems = array_values(array_filter(
                        array_map('trim', explode(',', $rawValue)),
                        static fn($v) => $v !== ''
                    ));

                    if (count($valueItems) > 1) {
                        // Replace with list
                        $frontmatter[$propName] = $valueItems;
                    } else {
                        // Replace with single scalar value (or empty string)
                        $frontmatter[$propName] = $valueItems[0] ?? '';
                    }
                }
            }
        }

        // Handle tags
        $setTag = $this->option('setTag');
        if ($setTag !== null) {
            $frontmatter['tags'] = [$setTag];
        } else {
            // Ensure tags array exists
            if (!isset($frontmatter['tags'])) {
                $frontmatter['tags'] = [];
            }
            if (!is_array($frontmatter['tags'])) {
                $frontmatter['tags'] = [$frontmatter['tags']];
            }

            // Add tags
            $addTags = $this->option('addTag');
            if (is_array($addTags) && !empty($addTags)) {
                foreach ($addTags as $tag) {
                    $tag = ltrim(trim($tag), '#');
                    if (!in_array($tag, $frontmatter['tags'], true)) {
                        $frontmatter['tags'][] = $tag;
                    }
                }
            }

            // Remove tags
            $removeTags = $this->option('removeTag');
            if (is_array($removeTags) && !empty($removeTags)) {
                foreach ($removeTags as $tag) {
                    $tag = ltrim(trim($tag), '#');
                    $frontmatter['tags'] = array_values(array_filter($frontmatter['tags'], fn($t) => $t !== $tag));
                }
            }

            // Clean up empty tags array
            if (empty($frontmatter['tags'])) {
                unset($frontmatter['tags']);
            }
        }

        // Handle content
        $newContent = $this->option('content');
        if ($newContent !== null) {
            $body = $newContent;
        }

        // Rebuild note content
        $frontmatterYaml = $noteService->buildFrontmatter($frontmatter);
        $newNoteContent = $frontmatterYaml . $body;

        // Write file
        if (file_put_contents($fullPath, $newNoteContent) === false) {
            throw new \RuntimeException("Could not write file: {$fullPath}");
        }
    }

    public function schedule(Schedule $schedule): void {}
}
