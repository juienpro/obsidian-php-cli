---
name: obsidian-php-cli
description: Use the Obsidian PHP CLI tool to manage Obsidian vaults. Create, search, modify, and delete notes with YAML frontmatter support. Use when the user wants to manage Obsidian notes, create notes, search their vault, update note properties, delete notes, or work with Obsidian markdown files from the command line.
---

# Using Obsidian PHP CLI

A command-line tool for managing Obsidian vaults. Create, search, modify, and delete notes with full YAML frontmatter support.

## Prerequisites

- Tool installed and configured with `VAULT_PATH` in `.env`
- Command executable: `./obsidian` (or `obsidian` if in PATH)
- Vault path must be set and accessible

## Commands Overview

| Command | Purpose | When to Use |
|---------|---------|-------------|
| `note:create` | Create new notes | User wants to create a note with frontmatter/tags |
| `note:search` | Search vault | Find notes by tags, properties, content, dates |
| `note:modify` | Update notes | Change tags, properties, or content of existing notes |
| `note:delete` | Delete notes | Remove notes from vault |
| `note:createFromTemplate` | Create from template | Generate notes using template files |

## Command Reference

### Create Note (`note:create`)

**When to use:** User wants to create a new note.

**Syntax:**
```bash
./obsidian note:create "path/to/folder" "Note Title" \
  --tags="tag1" --tags="tag2" \
  --propertyValue="property,value" \
  --content="Note body content"
```

**Arguments:**
- `path`: Relative path from vault root (e.g., `"Projects/2024"`)
- `title`: Note title (becomes filename)

**Options:**
- `--tags=*`: Add tags (repeatable, removes `#` prefix if present)
- `--propertyValue=*`: Property pairs as `property,value` (repeatable, same property creates array)
- `--content=`: Note body content (use `\n` for newlines)

**Examples:**
```bash
# Simple note
./obsidian note:create "Notes" "Daily Journal"

# With tags and properties
./obsidian note:create "Projects" "New Project" \
  --tags="project" --tags="active" \
  --propertyValue="status,planning" \
  --propertyValue="priority,high"

# With content
./obsidian note:create "Meetings" "Standup" \
  --tags="meeting" \
  --content="# Standup\n\n## Notes\n- Item 1"
```

**Output:** Success message with relative path to created note.

### Search Notes (`note:search`)

**When to use:** User wants to find notes matching criteria.

**Syntax:**
```bash
./obsidian note:search \
  --operator=AND \
  --tag="tag1" \
  --propertyValue="status,published" \
  --content="search term" \
  --modifiedAfter="2024-01-01" \
  --last=10 \
  --j
```

**Options:**
- `--operator=AND|OR`: Combine criteria (default: `AND`)
- `--path=*`: Match path(s) (exact/prefix match)
- `--pathContains=*`: Path contains string(s) (case-insensitive)
- `--tag=*`: Frontmatter tag(s) (note must have at least one)
- `--property=*`: Property name(s) that must exist
- `--propertyValue=*`: Property value match as `property,value`
- `--title=*`: Title substring match
- `--content=*`: Body content substring match
- `--modifiedAfter=YYYY-MM-DD`: Modified after date
- `--modifiedBefore=YYYY-MM-DD`: Modified before date
- `--last=N`: Show only last N modified notes
- `--j`: JSON output (includes matched parameters)

**Output:**
- **Without `--j`**: Table with ID, Path, Title, Last Modified
- **With `--j`**: JSON array with full note details
- **State**: Results stored in `storage/state.json` for modify/delete

**Important:** Search must run before modify/delete to populate note IDs.

**Examples:**
```bash
# Find notes with tag
./obsidian note:search --tag="todo"

# Find by property value
./obsidian note:search --propertyValue="status,draft"

# Complex search (AND logic)
./obsidian note:search \
  --path="Projects" \
  --tag="important" \
  --modifiedAfter="2024-01-01" \
  --last=5

# OR logic
./obsidian note:search \
  --operator=OR \
  --tag="meeting" \
  --tag="call"

# JSON output
./obsidian note:search --tag="project" --j
```

### Modify Notes (`note:modify`)

**When to use:** User wants to update existing notes (tags, properties, content).

**Prerequisite:** Run `note:search` first to populate note IDs.

**Syntax:**
```bash
./obsidian note:modify 0 1 2 \
  --addTag="newtag" \
  --removeTag="oldtag" \
  --propertyValue="status,published" \
  --content="New content" \
  --j
```

**Arguments:**
- `id*`: One or more note IDs from latest search (0, 1, 2, etc.)

**Options:**
- `--addTag=*`: Add tag(s) to existing tags
- `--setTag=`: Replace all tags with single tag
- `--removeTag=*`: Remove tag(s)
- `--propertyValue=*`: Set property value (replaces existing)
- `--content=`: Replace note body (preserves frontmatter)
- `--j`: JSON output

**Workflow:**
1. Search for notes: `./obsidian note:search --tag="draft"`
2. Note the IDs from output (e.g., 0, 1, 2)
3. Modify: `./obsidian note:modify 0 1 2 --propertyValue="status,published"`

**Examples:**
```bash
# Update status property
./obsidian note:search --propertyValue="status,draft"
./obsidian note:modify 0 1 --propertyValue="status,published"

# Add tag
./obsidian note:modify 0 --addTag="reviewed"

# Remove tag
./obsidian note:modify 0 --removeTag="draft"

# Replace all tags
./obsidian note:modify 0 --setTag="archived"

# Update content
./obsidian note:modify 0 --content="# Updated Title\n\nNew content here"
```

### Delete Notes (`note:delete`)

**When to use:** User wants to delete notes.

**Prerequisite:** Run `note:search` first to populate note IDs.

**Syntax:**
```bash
./obsidian note:delete 0 1 2 --y --j
```

**Arguments:**
- `id*`: One or more note IDs from latest search

**Options:**
- `--y`: Skip confirmation prompt
- `--j`: JSON output

**Workflow:**
1. Search: `./obsidian note:search --tag="temp"`
2. Delete: `./obsidian note:delete 0 1 --y`

**Examples:**
```bash
# With confirmation
./obsidian note:search --tag="temp"
./obsidian note:delete 0

# Skip confirmation
./obsidian note:delete 0 1 2 --y
```

### Create from Template (`note:createFromTemplate`)

**When to use:** User wants to create notes from template files with variable replacement.

**Syntax:**
```bash
./obsidian note:createFromTemplate \
  "path/to/folder" \
  "Note Title" \
  "templates/template.md" \
  --replace="variable,value" \
  --j
```

**Arguments:**
- `path`: Relative path to note
- `title`: Note title
- `template`: Path to template file (absolute or relative to vault root)

**Options:**
- `--replace=*`: Variable replacement as `string_to_replace,value`
- `--j`: JSON output

**Template Syntax:**
Use `{{variable_name}}` in template file. Variables replaced in both frontmatter and body.

**Example Template (`templates/meeting.md`):**
```markdown
---
title: {{title}}
date: {{date}}
tags:
  - meeting
attendees: {{attendees}}
---

# {{title}}

Date: {{date}}
Attendees: {{attendees}}

## Agenda

## Notes

## Action Items
```

**Usage:**
```bash
./obsidian note:createFromTemplate \
  "Meetings" \
  "Project Kickoff" \
  "templates/meeting.md" \
  --replace="date,2024-01-15" \
  --replace="attendees,John,Jane,Bob"
```

## Common Workflows

### Workflow 1: Create and Tag Notes

```bash
# Create note with tags
./obsidian note:create "Projects" "New Feature" \
  --tags="project" --tags="feature" \
  --propertyValue="status,planning" \
  --propertyValue="priority,high"
```

### Workflow 2: Find and Update Notes

```bash
# Step 1: Find notes
./obsidian note:search --propertyValue="status,draft"

# Step 2: Update them (use IDs from output)
./obsidian note:modify 0 1 2 --propertyValue="status,published"
```

### Workflow 3: Bulk Tag Operations

```bash
# Find notes
./obsidian note:search --path="Archive"

# Add tag to all found notes
./obsidian note:modify 0 1 2 3 4 --addTag="archived"
```

### Workflow 4: Find Recent Notes

```bash
# Last 10 modified notes this month
./obsidian note:search \
  --modifiedAfter="2024-01-01" \
  --last=10
```

### Workflow 5: Search and Delete

```bash
# Find temporary notes
./obsidian note:search --tag="temp" --modifiedBefore="2024-01-01"

# Delete them
./obsidian note:delete 0 1 2 --y
```

## Understanding Output

### Table Output (default)
```
+----+------------------+------------------+--------------+
| ID | Path             | Title            | Last Modified|
+----+------------------+------------------+--------------+
| 0  | Projects/note.md | Project Note     | 2024-01-15   |
| 1  | Notes/note2.md   | Another Note     | 2024-01-14   |
+----+------------------+------------------+--------------+
```

Use the **ID** column values for modify/delete commands.

### JSON Output (`--j` flag)
```json
[
  {
    "id": 0,
    "path": "Projects/note.md",
    "title": "Project Note",
    "lastModified": "2024-01-15",
    "matchedParameters": {
      "tag": ["project"]
    }
  }
]
```

## Important Notes

1. **State Management**: Search results stored in `storage/state.json`. Each new search overwrites previous state. Modify/delete commands use IDs from the latest search.

2. **Path Format**: Always use relative paths from vault root. Use forward slashes: `"Projects/2024"` not `"Projects\\2024"`.

3. **Property Values**: Format as `property,value`. Multiple values for same property create arrays.

4. **Tags**: Tags stored as arrays in frontmatter. `--tags` option accepts `#tag` or `tag` (prefix removed automatically).

5. **Content**: Use `\n` for newlines in `--content` option. Content replaces entire body (frontmatter preserved).

6. **Date Format**: Always use `YYYY-MM-DD` for date filters.

7. **Template Variables**: Use `{{variable}}` syntax. Variables replaced in both frontmatter YAML and markdown body.

8. **File Naming**: Notes created with title as filename. Auto-increments if duplicate exists (`title.md`, `title-1.md`, etc.).

## Error Handling

- **VAULT_PATH not set**: Check `.env` file has `VAULT_PATH=/path/to/vault`
- **Note not found**: Ensure search was run before modify/delete
- **Invalid IDs**: Use IDs from latest search output (0, 1, 2, etc.)
- **Template not found**: Check template path (can be absolute or relative to vault root)

## Best Practices

1. **Always search first** before modify/delete operations
2. **Use `--j` flag** when integrating with scripts or need structured output
3. **Use `--last=N`** to limit results to most recent notes
4. **Combine filters** with `--operator=AND` for precise searches
5. **Use templates** for repetitive note structures
6. **Verify with search** after bulk modifications
