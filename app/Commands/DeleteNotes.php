<?php

namespace App\Commands;

use App\Services\NoteService;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class DeleteNotes extends Command
{
    protected $signature = 'note:delete
        {id* : The ID(s) of notes to delete (from latest search result)}
        {--y : Don\'t ask for confirmation before deletion}
        {--j : Return result in JSON format}
    ';

    protected $description = 'Delete one or more notes by their ID from the latest search result';

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

        // Collect notes to delete
        $notesToDelete = [];
        foreach ($ids as $id) {
            $noteData = $results[$id] ?? null;
            if ($noteData === null) {
                if ($this->option('j')) {
                    $this->line(json_encode(['success' => false, 'error' => "Note with ID {$id} not found in search results."]));
                } else {
                    $this->error("Note with ID {$id} not found in search results.");
                }
                return self::FAILURE;
            }
            $notesToDelete[] = $noteData;
        }

        // Confirm deletion unless -y flag is set
        if (!$this->option('y')) {
            $this->warn('The following notes will be deleted:');
            foreach ($notesToDelete as $note) {
                $title = $note['title'] ?? '(no title)';
                $this->line("  - {$note['relativePath']} ({$title})");
            }
            if (!$this->confirm('Are you sure you want to delete these notes?', false)) {
                if ($this->option('j')) {
                    $this->line(json_encode(['success' => false, 'error' => 'Deletion cancelled.']));
                } else {
                    $this->info('Deletion cancelled.');
                }
                return self::SUCCESS;
            }
        }

        // Delete notes
        $success = true;
        $errors = [];
        foreach ($notesToDelete as $note) {
            $fullPath = $note['fullPath'] ?? null;
            if ($fullPath === null || !file_exists($fullPath)) {
                $errors[] = "Note file not found: {$note['relativePath']}";
                $success = false;
                continue;
            }

            if (@unlink($fullPath)) {
                if (!$this->option('j')) {
                    $this->info("Deleted: {$note['relativePath']}");
                }
            } else {
                $errors[] = "Failed to delete: {$note['relativePath']}";
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
            if (!$success) {
                foreach ($errors as $error) {
                    $this->error($error);
                }
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    public function schedule(Schedule $schedule): void {}
}
