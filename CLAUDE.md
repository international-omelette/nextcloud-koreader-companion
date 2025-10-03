# KOReader Companion - AI Agent Development Guide

## Your Mindset as a coding agent
Prioritize substance, clarity, and depth. Challenge all my proposals, designs, and conclusions as hypotheses to be tested. Sharpen follow-up questions for precision, surfacing hidden assumptions, trade offs, and failure modes early. Default to terse, logically structured, information-dense responses unless detailed exploration is required. Skip unnecessary praise unless grounded in evidence. Explicitly acknowledge uncertainty when applicable. Always propose at least one alternative framing. Accept critical debate as normal and preferred. Treat all factual claims as provisional unless cited or clearly justified. Cite when appropriate. Acknowledge when claims rely on inference or incomplete information. Favor accuracy over sounding certain. When citing, please tell me in-situ, including reference links. Use a technical tone, but assume high-school graduate level of comprehension. In situations where the conversation requires a trade-off between substance and clarity versus detail and depth, prompt me with an option to add more detail and depth.

## Overview

**What**: Nextcloud app providing OPDS 1.2 library + KOReader sync API
**Stack**: PHP 8.0+, Nextcloud 30-31, native NC APIs (IRootFolder, IConfig, IUserSession)
**Version**: 1.2.0 (Oct 2025)
**Repo**: https://github.com/international-omelette/nextcloud-koreader-companion

**Core capabilities**:
- OPDS catalog with HTTP Basic Auth (native NC credentials)
- KOReader sync (custom password, MD5 headers: `x-auth-user`, `x-auth-key`)
- Event-driven metadata extraction (EPUB/PDF)
- Web UI with infinite scroll, modal uploads
- Privacy-first: zero external dependencies

**Architecture shift (v1.0 → v1.2)**:
- Path-based → ID-based file operations
- Background indexing → Real-time event-driven processing
- Admin settings → User-level configuration

### System Design

```
Authentication:
  Web UI  → Nextcloud session
  OPDS    → HTTP Basic (username:password)
  KOReader→ MD5 header (`x-auth-user` + `x-auth-key`)

Data Flow:
  File Upload → FileCreationListener → BookService.extractMetadata()
             → Store in oc_koreader_books (key: file_id)
  OPDS Request → OpdsController → BookService.getBooks() → XML feed
  Sync Request → KoreaderController → Hash validation → oc_koreader_progress

Core Services:
  BookService            - Metadata extraction, pagination, real-time scanning
  PdfMetadataExtractor   - PDF-specific parsing (smalot/pdfparser)
  DocumentHashGenerator  - KOReader hash generation
  FilenameService        - Batch rename operations
```

**Directory map**:
```
lib/
├── Controller/
│   ├── PageController.php       # Web UI + settings API
│   ├── OpdsController.php       # OPDS 1.2 feeds (Basic Auth)
│   ├── KoreaderController.php   # Sync API (MD5 auth)
│   └── SettingsController.php   # User config (folder, auto-rename)
├── Service/
│   ├── BookService.php          # Core metadata + pagination
│   ├── PdfMetadataExtractor.php # Enhanced PDF support
│   ├── FilenameService.php      # Batch operations
│   └── DocumentHashGenerator.php# KOReader hashing
├── Listener/
│   ├── FileCreationListener.php # Real-time metadata extraction
│   └── FileDeleteListener.php   # Cleanup orphaned records
└── Migration/                   # Database schema (oc_koreader_*)

appinfo/routes.php  # API endpoint definitions
templates/page.php  # Main UI template
js/koreader.js      # CSP-compliant frontend (no inline handlers)
css/books.css       # Nextcloud-style responsive design
```

## Quick Reference

**Deploy & Test**:
```bash
./test_scripts/reset_and_deploy.sh       # Deploy to dev container
./test_scripts/test_opds.sh -v           # OPDS + Basic Auth validation
./test_scripts/test_koreader.sh -v       # KOReader sync compliance
```

**Key files**:
- `BookService.php:38` - Pagination logic
- `OpdsController.php` - OPDS feed generation
- `KoreaderController.php:updateProgress()` - Sync endpoint
- `routes.php` - All API paths
- `info.xml` - App metadata, NC version compat

**Database**:
- `oc_koreader_books` - Metadata cache (file_id primary key)
- `oc_koreader_progress` - Reading positions
- `oc_preferences` - User settings (folder, password)

**Critical paths**:
- OPDS feed: `https://nc.example.com/apps/koreader_companion/opds`
- Sync API: `https://nc.example.com/apps/koreader_companion/sync/*`
- Signing certs: `~/.nextcloud/certificates/`

## Development Context

**Design philosophy**:
- **Cognitive load reduction** > clever abstractions
- **Self-documenting code** > comments
- **Linear logic** > complex conditionals
- **Deep modules** (simple interfaces, complex internals)
- **Related logic together** (avoid over-fragmentation)

**Hard constraints**:
- CSP compliance: ZERO inline JS (`addEventListener()` only)
- Nextcloud-native auth (no custom backends)
- OPDS 1.2 spec adherence
- KOReader sync API compatibility
- Privacy-first (no telemetry, external calls)

**Testing requirements**:
1. Run OPDS + KOReader test scripts after changes
2. Address ALL failures before completion
3. Test with various file types (EPUB, PDF, edge cases)
4. Verify auth flows with real credentials

**Common pitfalls**:
- ❌ Don't use inline event handlers (CSP violation)
- ❌ Don't assume libraries exist (check `composer.json`)
- ❌ Don't store passwords in database (use `oc_preferences`)
- ❌ Don't create files when editing works
- ❌ Don't add Claude Code refs to commits
- ❌ Don't work on multiple tasks simultaneously

**Task management**:
- Use `TodoWrite` for 3+ step tasks
- ONE task `in_progress` at a time
- Mark complete IMMEDIATELY after finishing
- Research → Plan → Execute → Test

## API Standards

**OPDS 1.2**:
- Content-Type: `application/atom+xml;profile=opds-catalog`
- Auth: HTTP Basic (NC username/password)
- Facets: `/opds/authors`, `/opds/series`, `/opds/genres`, `/opds/languages`
- Pagination: `?page=N` support
- Spec: http://opds-spec.org/specs/opds-catalog-1-2/

**KOReader Sync**:
- Content-Type: `application/vnd.koreader.v1+json`
- Auth: MD5(`username` + `password`) in `x-auth-key` header
- Endpoints: `/sync/users/auth`, `/sync/syncs/progress`, `/sync/healthcheck`
- Document hash: MD5 of file content
- Spec: https://github.com/koreader/koreader/wiki

**Response patterns**:
```php
// Success
return new DataResponse(['data' => $result]);

// Error
return new DataResponse(
    ['error' => 'Message', 'code' => 'ERROR_CODE'],
    Http::STATUS_BAD_REQUEST
);
```

## Performance Targets

- OPDS feed (100 books): <500ms
- Cover thumbnail: <200ms
- Search (1000 books): <1s
- Memory (5000 books): <100MB

**Optimization strategies**:
- Cache metadata in `oc_koreader_books`
- Paginate large result sets
- Index database queries properly
- Event-driven updates (no polling)

## Agent Workflow

1. **Research first**: Use `Task` tool for multi-round searches
2. **Check existing patterns**: Read neighboring files before implementing
3. **Verify dependencies**: Check `composer.json` for library availability
4. **Implement with TodoWrite**: Track multi-step tasks
5. **Test comprehensively**: Run all test scripts
6. **Never skip failures**: Address every test error

**Specialized agents**:
- `.claude/agents/nextcloud-app-developer.md` - NC-specific development
- `.claude/agents/spec-researcher.md` - API spec research

**Custom commands**:
- `/commit` - Create commit with proper formatting
- `/diff-review` - Comprehensive code review
- `/draft-release` - Semantic versioning + GitHub release

## Anti-patterns

**Authentication**:
- ❌ Custom user backends (use NC native)
- ❌ Database password storage (use `oc_preferences`)
- ❌ Custom auth middleware

**Code quality**:
- ❌ Explanatory comments (make code self-documenting)
- ❌ Unverified library usage
- ❌ Inline event handlers
- ❌ Creating new files unnecessarily

**Workflow**:
- ❌ Committing without explicit request
- ❌ Multiple simultaneous tasks
- ❌ Skipping test validation
- ❌ Batching todo completions (mark immediately)

## Critical Decision Framework

Before implementing, ask:
1. **Does this reduce cognitive load?**
2. **Does it maintain privacy-first approach?**
3. **Is it Nextcloud-native?**
4. **Does it preserve OPDS/KOReader compatibility?**
5. **Will tests validate correctness?**

If any answer is "no" or "uncertain", propose alternatives.

---
*Last Updated: 2025-10-03 | v1.2.0*
