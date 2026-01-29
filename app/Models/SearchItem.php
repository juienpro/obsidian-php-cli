<?php

namespace App\Models;

class SearchItem
{
    public function __construct(
        public readonly int $index,
        public readonly string $fullPath,
        public readonly string $relativePath,
        public readonly string $title,
        public readonly ?string $lastModificationDate,
        /** @var array<string, mixed> Search parameters that matched (e.g. 'path' => 'foo', 'tags' => ['tag1']) */
        public readonly array $matchedParameters,
        /** @var array<string, mixed> All frontmatter properties from the note */
        public readonly array $frontmatter = [],
    ) {}

    public function toArray(bool $includeFrontmatter = false): array
    {
        $result = [
            'index' => $this->index,
            'fullPath' => $this->fullPath,
            'relativePath' => $this->relativePath,
            'title' => $this->title,
            'lastModificationDate' => $this->lastModificationDate,
            'matchedParameters' => $this->matchedParameters,
        ];

        if ($includeFrontmatter) {
            $result['frontmatter'] = $this->frontmatter;
        }

        return $result;
    }
}
