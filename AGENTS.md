# KOReader Companion - AI Agent Development Guide

## Your Mindset as a coding agent
Prioritize substance, clarity, and depth. Challenge all my proposals, designs, and conclusions as hypotheses to be tested. Sharpen follow-up questions for precision, surfacing hidden assumptions, trade offs, and failure modes early. Default to terse, logically structured, information-dense responses unless detailed exploration is required. Skip unnecessary praise unless grounded in evidence. Explicitly acknowledge uncertainty when applicable. Always propose at least one alternative framing. Accept critical debate as normal and preferred. Treat all factual claims as provisional unless cited or clearly justified. Cite when appropriate. Acknowledge when claims rely on inference or incomplete information. Favor accuracy over sounding certain. When citing, please tell me in-situ, including reference links. Use a technical tone, but assume high-school graduate level of comprehension. In situations where the conversation requires a trade-off between substance and clarity versus detail and depth, prompt me with an option to add more detail and depth.

## Overview

**What**: Nextcloud app providing OPDS 1.2 library + KOReader sync API
**Stack**: PHP 8.1+ (floor set by NC 30; NC 34 needs 8.2+; composer.json declares >=8.0), Nextcloud 30-34, native NC APIs (IRootFolder, IConfig, IUserSession)
**Version**: 1.3.0 (Oct 2026)
**Repo**: https://github.com/international-omelette/nextcloud-koreader-companion

**Core capabilities**:
- OPDS catalog with HTTP Basic Auth (supports app passwords, LDAP, 2FA)
- KOReader sync (custom password, MD5 headers: `x-auth-user`, `x-auth-key`)
- Background-job metadata extraction (EPUB/PDF), queued from file events
- Web UI with infinite scroll, modal uploads
- Privacy-first: zero external dependencies

**Architecture shift (v1.0 → v1.3)**:
- Path-based → ID-based file operations
- Inline extraction in requests/listeners → event-queued background jobs (v1.3); request paths are DB-only
- Admin settings → User-level configuration

### System Design

```
Authentication:
  Web UI  → Nextcloud session
  OPDS    → HTTP Basic via IUserSession->logClientIn() (app passwords, LDAP, 2FA)
  KOReader→ MD5 header (`x-auth-user` + `x-auth-key`)

Data Flow:
  File Upload → FileCreationListener → queue IndexFileJob {fileId,userId}
  IndexFileJob → BookService.indexFile()
             → write status marker (processing, attempts++) BEFORE extraction
             → extract → oc_koreader_metadata (key: file_id, status ok|failed)
             → Generate hashes → Store in oc_koreader_hash_mapping
  No Node event (files:scan, upgrades, cleared library):
    ReconcileLibraryJob (hourly TimedJob, callForSeenUsers) → queue ReconcileUserJob
    ReconcileUserJob → BookService.reconcileUser() (bounded; re-queues itself until done)
    occ koreader:index [user] → unbounded reconcileUser()
  Web / OPDS (incl. download, thumbnail) / sync / batch rename → DB-only, no FS scan
  OPDS Request → OpdsController → BookService.getBooks() → XML feed
  Sync Request → KoreaderController → Hash validation → oc_koreader_sync_progress
             → hash miss: queue ReconcileUserJob (max once/600s/user; no inline indexing)

Indexing status (oc_koreader_metadata, migration Version0007):
  status pending|processing|ok|failed, attempts, failed_at
  Marker written before extraction → fatal errors (OOM) leave `processing`
  processing with attempts >= 3 → failed (not retried)
  Newer file mtime → attempts reset (re-indexed)
  PDF extraction skipped above 20 MB

Core Services:
  BookService            - indexFile()/reconcileUser(), metadata extraction, DB-only queries + pagination
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
├── BackgroundJob/
│   ├── IndexFileJob.php         # Index one file {fileId,userId}
│   ├── ReconcileUserJob.php     # Bounded per-user reconcile, re-queues itself
│   └── ReconcileLibraryJob.php  # Hourly: queue ReconcileUserJob per seen user
├── Command/
│   ├── IndexLibraryCommand.php  # occ koreader:index [user]
│   └── GenerateBookHashesCommand.php
├── Listener/
│   ├── FileCreationListener.php # Queues IndexFileJob
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
./test_scripts/test_indexing.sh --root <env> --base-url <url>  # Background indexing guarantees
```

**Key files**:
- `BookService::getBooks()` - Pagination logic
- `BookService::indexFile()` / `reconcileUser()` - Indexing + status tracking
- `OpdsController.php` - OPDS feed generation
- `KoreaderController.php:updateProgress()` - Sync endpoint
- `routes.php` - All API paths
- `info.xml` - App metadata, NC version compat

**Database**:
- `oc_koreader_metadata` - Metadata cache (file_id primary key)
- `oc_koreader_hash_mapping` - Document hash mappings (auto-generated on upload)
- `oc_koreader_sync_progress` - Reading positions
- `oc_preferences` - User settings (folder, password)

**Critical paths**:
- OPDS feed: `https://nc.example.com/apps/koreader_companion/opds`
- Sync API: `https://nc.example.com/apps/koreader_companion/sync/*`
- Signing certs: `~/.nextcloud/certificates/`

## Local Test Environment (no Docker / no root)

This project can be tested end-to-end without Docker or root by running a
self-contained Nextcloud + database stack entirely in user space. Use this when
Docker is unavailable.

**Bootstrap**:
```bash
./test_scripts/bootstrap_test_env.sh                    # PHP + MariaDB + NC34 + app
./test_scripts/test_opds.sh     --base-url "http://127.0.0.1:8090/index.php"
./test_scripts/test_koreader.sh --base-url "http://127.0.0.1:8090/index.php"
./test_scripts/test_indexing.sh --root "$KOREADER_TESTENV" --base-url "http://127.0.0.1:8090/index.php"
./test_scripts/teardown_test_env.sh                     # stop the stack (keeps files)
./test_scripts/teardown_test_env.sh --wipe              # stop and delete $KOREADER_TESTENV
```

What the script provisions (all under `$KOREADER_TESTENV`, default `~/.koreader-testenv`):
- Static PHP 8.4 binary from https://dl.static-php.dev (no system packages installed)
- MariaDB 10.11 binary tarball, initialized and started in user space
- Nextcloud 34 tarball, installed against that database
- Composer dependencies installed into a throwaway copy of the app
- The app deployed, enabled, a test EPUB seeded and indexed via `occ koreader:index`, KOReader password set to `test123`
- A `php -S`-served Nextcloud on `127.0.0.1:8090` (admin / admin)

Notes / gotchas:
- The built-in PHP server requires `/index.php/...` in URLs. Pass
  `--base-url "http://127.0.0.1:8090/index.php"` to the test scripts.
- KOReader sync stores `md5(password)` in `oc_preferences` under
  `koreader_companion/koreader_sync_password`, **not the plaintext**. To set it
  manually: `occ user:setting admin koreader_companion koreader_sync_password "$(printf test123 | md5sum | cut -d' ' -f1)"`.
- **MariaDB silently adapts oversized indexes** (prefixes long non-unique keys,
  e.g. `(768)`; converts long unique keys to `USING HASH`); MySQL instead rejects
  them. The `1071 Specified key was too long` failure in issue #9 is therefore
  **MySQL-specific** — reproduce with MySQL 8.0 (`bootstrap_test_env.sh --db mysql`),
  which is what the Nextcloud snap ships per issue #9. MySQL also needs
  `libaio1`/`libnuma1`
  on the host (the script warns if they are missing); MariaDB bundles its own.
- Nextcloud 34 needs PHP 8.2-8.4; the static PHP 8.4 build works. Older NC (30/31)
  may reject PHP 8.4 and would need a PHP 8.3 static build instead.
- `test_indexing.sh` drives the DB via `test_scripts/db_exec.php`, which reads
  the env's `config.php` (works on MariaDB and SQLite).
- Files added via `occ files:scan` fire no Node events, so they are not indexed
  until `occ koreader:index` or cron (ReconcileLibraryJob) runs.
- Repeated OPDS runs accumulate Nextcloud bruteforce throttling from the
  intentional bad-auth tests; reset with `occ security:bruteforce:reset 127.0.0.1`.
- `/tmp` is often wiped; keep `KOREADER_TESTENV` somewhere persistent.

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
4. Verify auth flows with real credentials, app passwords, and LDAP (if available)

**Common pitfalls**:
- ❌ Don't use inline event handlers (CSP violation)
- ❌ Don't assume libraries exist (check `composer.json`)
- ❌ Don't store passwords in database (use `oc_preferences`)
- ❌ Don't use app-level config in event listeners (extract userId from path for per-user settings)
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
- Auth: HTTP Basic via `IUserSession->logClientIn()` (supports app passwords, LDAP, 2FA)
- Pattern: Follows DAV auth implementation (see `apps/dav`)
- Facets: `/opds/authors`, `/opds/series`, `/opds/genres`, `/opds/languages`
- Pagination: `?page=N` support
- Spec: http://opds-spec.org/specs/opds-catalog-1-2/

**KOReader Sync**:
- Response Content-Type: `application/json` (deliberate; `application/vnd.koreader.v1+json` broke the KOReader client — see commit 7b6a2d2 / koreader#13539)
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
- Cache metadata in `oc_koreader_metadata`
- Paginate large result sets
- Index database queries properly
- Event-queued background jobs (no polling, no filesystem work in request paths)

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
- ❌ IUserManager->checkPassword() for HTTP auth (breaks app passwords/LDAP; use IUserSession->logClientIn())

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
*Last Updated: 2026-10-18 | v1.3.0*
