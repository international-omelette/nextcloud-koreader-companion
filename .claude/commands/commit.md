---
argument-hint: "[topic]"
description: Commit changes to the koreader-companion repository
---

This command commits changes to the koreader-companion repository (public GitHub repo).

The working directory is already set to the repository root.

## Parameters

**topic** (optional): A hint about what this commit is about, accessed via `$1`. This helps determine which files are relevant to stage and commit.

Examples:
- `/commit koreader-api` - commit changes related to KOReader API
- `/commit opds-feeds` - commit OPDS feed related changes
- `/commit ui-improvements` - commit UI/frontend changes
- `/commit metadata-extraction` - commit metadata handling changes
- `/commit` - commit all changed files (analyze and determine appropriate grouping)

## Automated Versioning Conventions

The repository uses automated semantic versioning based on commit messages:

### Commit Message Format
```
<type>: <description>

[optional body]
```

### Version Bumping Rules
- **MAJOR** (x.0.0): `BREAKING CHANGE:` in commit message
- **MINOR** (x.y.0): `feat:` prefix (new features)  
- **PATCH** (x.y.z): `fix:` prefix (bug fixes)
- **No bump**: `chore:`, `docs:`, `refactor:`, `style:`, `test:`

### Examples
```bash
feat: add OPDS authentication support          # → 1.1.0
fix: resolve metadata extraction bug           # → 1.0.1  
feat: implement KOReader sync API              # → 1.2.0
BREAKING CHANGE: remove deprecated endpoints   # → 2.0.0
chore: update dependencies                     # → no version change
```

### Before Committing
Update these files if necessary:
- README.md -> Keep it simple, only change for major changes
Check all added comments if they are redundant (if the code is self explanatory) or if there is documentational / logical value in them. If not, remove them.

### Commit Message Rules
- Remove all references to Claude Code in commit messages
- Use imperative mood: "add feature" not "adds feature"
- Keep first line under 50 characters
- Reference issues with #123 format if applicable

### Automated Release Process
- Pushes to main/master trigger version calculation
- GitHub releases are created automatically
- Tarballs are built and attached to releases
- Version in info.xml is updated automatically

### After committing
- Launch a subagent to update @CLAUDE.md