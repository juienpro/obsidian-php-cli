# Obsidian PHP CLI

A powerful command-line tool for managing your Obsidian vault. Create, search, modify, and delete notes directly from the terminal with support for YAML frontmatter, tags, properties, and template-based note creation.

## Installation

### Requirements

- PHP 8.2 or higher
- Composer
- An Obsidian vault directory

### Setup

1. Clone or download this repository:
   ```bash
   git clone <repository-url>
   cd obsidian-php-cli
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Configure your vault path by creating a `.env` file in the project root:
   ```bash
   cp .env.example .env  # If .env.example exists, or create .env manually
   ```

4. Edit `.env` and set your vault path:
   ```
   VAULT_PATH=/path/to/your/obsidian/vault
   ```

5. Make the CLI executable (optional, for global access):
   ```bash
   chmod +x obsidian
   ```

6. (Optional) Add to your PATH for global access:
   ```bash
   # Add to ~/.bashrc or ~/.zshrc
   export PATH="$PATH:/path/to/obsidian-php-cli"
   ```

## Features

- **Create Notes**: Create new notes with frontmatter, tags, and custom properties
- **Search Notes**: Powerful search with multiple filters (path, tags, properties, content, dates)
- **Modify Notes**: Update tags, properties, and content of existing notes
- **Delete Notes**: Safely delete notes with confirmation prompts
- **Template Support**: Create notes from templates with variable replacement
- **YAML Frontmatter**: Full support for parsing and manipulating YAML frontmatter
- **JSON Output**: Optional JSON output for integration with other tools
- **ID-based Operations**: Work with notes using IDs from search results

## Usage

### Create a Note

Create a new note with title, tags, and properties:

```bash
./obsidian note:create "path/to/notes" "My Note Title" \
  --tags="tag1" --tags="tag2" \
  --propertyValue="status,draft" \
  --content="Note content here"
```

**Arguments:**
- `path`: Relative path from vault root (e.g., `"Projects/2024"`)
- `title`: Title of the note

**Options:**
- `--tags=*`: Tags to apply (can be used multiple times)
- `--propertyValue=*`: Property name and value pairs in format `property,value` (can be used multiple times)
- `--content=`: Content for the note body

### Search Notes

Search your vault with flexible criteria:

```bash
./obsidian note:search \
  --operator=AND \
  --path="Projects" \
  --tag="important" \
  --property="status" \
  --propertyValue="status,published" \
  --title="Meeting" \
  --content="action items" \
  --modifiedAfter="2024-01-01" \
  --modifiedBefore="2024-12-31" \
  --last=10 \
  --j
```

**Options:**
- `--operator=`: Combine criteria with `AND` or `OR` (default: `AND`)
- `--path=*`: Relative path(s) from vault root (exact or prefix match)
- `--pathContains=*`: String(s) that relative path must contain (case insensitive)
- `--tag=*`: Frontmatter tag(s) (note must have at least one)
- `--property=*`: Frontmatter property name(s) that must exist
- `--propertyValue=*`: Property name and value pairs in format `property_name,property_value`
- `--title=*`: Title(s) to match (substring match)
- `--content=*`: Content substring(s) to match in body
- `--modifiedBefore=`: Filter by last modification date before (format: `YYYY-MM-DD`)
- `--modifiedAfter=`: Filter by last modification date after (format: `YYYY-MM-DD`)
- `--last=`: Show only the last N modified notes from search results
- `--j`: Output results in JSON format (includes matched parameters)

**Output:**
- Without `--j`: Displays a table with ID, Path, Title, and Last Modified
- With `--j`: Returns JSON array with full note details and matched parameters
- Search results are stored in `storage/state.json` for use with modify/delete commands

### Modify Notes

Modify notes by their ID from the latest search result:

```bash
./obsidian note:modify 0 1 2 \
  --addTag="updated" \
  --removeTag="draft" \
  --propertyValue="status,published" \
  --content="Updated content" \
  --j
```

**Arguments:**
- `id*`: One or more note IDs from the latest search result

**Options:**
- `--propertyValue=*`: Property name and value pairs in format `property_name,value` (replaces existing value)
- `--addTag=*`: Tags to add (can be used multiple times)
- `--setTag=`: Replace all tags with a single tag
- `--removeTag=*`: Tags to remove (can be used multiple times)
- `--content=`: New content for the note body (replaces existing content)
- `--j`: Return result in JSON format

**Note:** You must run a search command first to populate the state with note IDs.

### Delete Notes

Delete notes by their ID from the latest search result:

```bash
./obsidian note:delete 0 1 2 --y --j
```

**Arguments:**
- `id*`: One or more note IDs from the latest search result

**Options:**
- `--y`: Skip confirmation prompt (use with caution)
- `--j`: Return result in JSON format

**Note:** By default, the command will ask for confirmation before deletion. Use `--y` to skip confirmation.

### Create from Template

Create a note from a template file with variable replacement:

```bash
./obsidian note:createFromTemplate \
  "Projects/2024" \
  "Project Name" \
  "templates/project.md" \
  --replace="project_name,My Project" \
  --replace="date,2024-01-15" \
  --j
```

**Arguments:**
- `path`: Relative path to the note
- `title`: Title of the note
- `template`: Path to the template file (can be absolute or relative to vault root)

**Options:**
- `--replace=*`: Variable replacement pairs in format `string_to_replace,value` (use `{{variable}}` syntax in template)
- `--j`: Output result in JSON format

**Template Variables:**
Use `{{variable_name}}` syntax in your template file. Variables will be replaced in both frontmatter and body content.

## Examples

### Example 1: Create a meeting note

```bash
./obsidian note:create "Meetings/2024" "Weekly Standup" \
  --tags="meeting" --tags="weekly" \
  --propertyValue="date,2024-01-15" \
  --propertyValue="attendees,John,Jane,Bob" \
  --content="# Weekly Standup\n\n## Agenda\n- Review progress\n- Discuss blockers"
```

### Example 2: Find all notes with a specific tag modified this month

```bash
./obsidian note:search \
  --tag="todo" \
  --modifiedAfter="2024-01-01" \
  --last=5
```

### Example 3: Update status of multiple notes

```bash
# First, search for notes
./obsidian note:search --property="status" --propertyValue="status,draft"

# Then modify them (using IDs from search results)
./obsidian note:modify 0 1 2 --propertyValue="status,published"
```

### Example 4: Create notes from a template

Create a template file `templates/meeting.md`:
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

Then create notes from it:
```bash
./obsidian note:createFromTemplate \
  "Meetings" \
  "Project Kickoff" \
  "templates/meeting.md" \
  --replace="date,2024-01-15" \
  --replace="attendees,John,Jane,Bob"
```

## Configuration

The application requires a `.env` file in the project root with the following variable:

```
VAULT_PATH=/path/to/your/obsidian/vault
```

The vault path should point to your Obsidian vault directory (the folder containing your `.obsidian` folder and markdown files).

## State Management

Search results are stored in `storage/state.json`. This file maintains the mapping between note IDs and their full paths, allowing you to reference notes by ID in modify and delete operations. Each new search overwrites the previous state.

## License

MIT License
