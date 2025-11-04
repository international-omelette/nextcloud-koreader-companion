# Test Scripts

Comprehensive test suite for KOReader Companion app functionality.

## Available Test Scripts

### Core API Tests

#### `test_opds.sh`
Tests OPDS 1.2 feed functionality with HTTP Basic authentication.

**Usage:**
```bash
./test_scripts/test_opds.sh [OPTIONS]

Options:
  -v, --verbose     Show detailed output
  --base-url URL    Set base URL (default: http://localhost:8080)
  --username USER   Set username (default: admin)
  --password PASS   Set password (default: admin)
  -h, --help        Show this help
```

**Tests:**
- OPDS catalog discovery
- Authentication (Basic Auth)
- Navigation feeds (authors, series, genres)
- Pagination support
- OPDS compliance

---

#### `test_koreader.sh`
Tests KOReader sync API with MD5 header authentication.

**Usage:**
```bash
./test_scripts/test_koreader.sh [OPTIONS]

Options:
  -v, --verbose     Show detailed output
  --base-url URL    Set base URL (default: http://localhost:8080)
  --username USER   Set username (default: admin)
  --password PASS   Set KOReader password (default: test123)
  -h, --help        Show this help
```

**Tests:**
- Authentication endpoint (`/sync/users/auth`)
- Progress sync (`/sync/syncs/progress`)
- Progress retrieval (`GET /sync/syncs/progress/:document`)
- Health check (`/sync/healthcheck`)
- Document hash matching (binary + filename)

---

### File Operation Tests

#### `test_file_rename.sh` ⭐ NEW
Tests that filename hash updates correctly when files are renamed.

**Usage:**
```bash
./test_scripts/test_file_rename.sh [OPTIONS]

Options:
  -v, --verbose     Show detailed output
  --base-url URL    Set base URL (default: http://localhost:8080)
  --username USER   Set username (default: admin)
  --password PASS   Set password (default: admin)
  --folder NAME     Set eBooks folder (default: eBooks)
  -h, --help        Show this help
```

**Tests:**
1. File upload triggers metadata extraction
2. Initial filename_hash matches MD5(filename)
3. File rename via WebDAV
4. Automatic filename_hash update in `oc_koreader_metadata`
5. New hash matches MD5(new_filename)
6. Metadata record integrity (no duplicates/orphans)

**Critical for:** KOReader sync reliability after file organization

---

#### `test_file_deletion.sh` ⭐ NEW
Tests that file deletion properly cleans up metadata and sync progress.

**Usage:**
```bash
./test_scripts/test_file_deletion.sh [OPTIONS]

Options:
  -v, --verbose           Show detailed output
  --base-url URL          Set base URL (default: http://localhost:8080)
  --username USER         Set username (default: admin)
  --password PASS         Set NC password (default: admin)
  --koreader-password PW  Set KOReader password (default: test123)
  --folder NAME           Set eBooks folder (default: eBooks)
  -h, --help              Show this help
```

**Tests:**
1. File upload + metadata + hash generation
2. Sync progress creation (filename_hash + binary_hash)
3. File deletion via WebDAV
4. Automatic cleanup of `oc_koreader_metadata`
5. Automatic cleanup of `oc_koreader_sync_progress` (all hashes)
6. No orphaned records in database
7. Atomic transaction-based cleanup

**Critical for:** Preventing database bloat and orphaned sync data

---

### Deployment Tests

#### `reset_and_deploy.sh`
Rebuilds and deploys the app to a Docker-based Nextcloud test environment.

**Usage:**
```bash
./test_scripts/reset_and_deploy.sh
```

**Requirements:**
- Docker and docker-compose
- Nextcloud test environment configured

---

## Test Environment Requirements

### For API Tests (`test_opds.sh`, `test_koreader.sh`)
- Running Nextcloud instance
- KOReader Companion app installed and enabled
- User account with credentials

### For File Operation Tests (`test_file_rename.sh`, `test_file_deletion.sh`)
- Running Nextcloud instance with Docker
- Database access via `docker exec`
- WebDAV enabled
- File event listeners active

### For Deployment Tests
- Docker and docker-compose installed
- Port 8080 available

---

## Running All Tests

```bash
# Set up environment
export BASE_URL="http://localhost:8080"
export USERNAME="admin"
export PASSWORD="admin"
export KOREADER_PASSWORD="test123"

# Run comprehensive test suite
./test_scripts/test_opds.sh -v
./test_scripts/test_koreader.sh -v
./test_scripts/test_file_rename.sh -v
./test_scripts/test_file_deletion.sh -v
```

---

## CI/CD Integration

These scripts can be integrated into GitHub Actions or other CI/CD pipelines:

```yaml
- name: Test OPDS API
  run: ./test_scripts/test_opds.sh --base-url ${{ env.TEST_URL }}

- name: Test KOReader Sync
  run: ./test_scripts/test_koreader.sh --base-url ${{ env.TEST_URL }}

- name: Test File Operations
  run: |
    ./test_scripts/test_file_rename.sh -v
    ./test_scripts/test_file_deletion.sh -v
```

---

## Test Data Cleanup

All test scripts automatically clean up their test data after completion. If tests fail midway:

```bash
# Manual cleanup via WebDAV
curl -X DELETE -u admin:admin \
  http://localhost:8080/remote.php/dav/files/admin/eBooks/test-*

# Manual cleanup via database
docker exec nextcloud-db mysql -u nextcloud -pnextcloud nextcloud \
  -e "DELETE FROM oc_koreader_metadata WHERE file_path LIKE '%test-%';"
```

---

## Troubleshooting

### "Docker not available" warnings
The file operation tests require Docker for database access. Without Docker, they will skip database verification but still test WebDAV operations.

### Tests timeout
Increase event processing wait times by editing `sleep` values in test scripts.

### Authentication failures
Verify KOReader sync password is set in app settings:
```bash
docker exec -u www-data nextcloud php occ config:user:get admin koreader_companion koreader_sync_password
```

### Database connection errors
Ensure database container is named `nextcloud-db` or update `db_query()` function in scripts.

---

## Contributing

When adding new features, please:
1. Add corresponding test cases to existing scripts, or
2. Create new test scripts following the established patterns
3. Update this README with test descriptions
4. Ensure tests run cleanly in CI/CD environment

---

## Test Coverage

Current test coverage:

| Component | Coverage | Scripts |
|-----------|----------|---------|
| OPDS API | ✅ Full | `test_opds.sh` |
| KOReader Sync | ✅ Full | `test_koreader.sh` |
| File Rename | ✅ Full | `test_file_rename.sh` |
| File Deletion | ✅ Full | `test_file_deletion.sh` |
| Metadata Extraction | ⚠️ Partial | Via file tests |
| Upload Events | ⚠️ Partial | Via file tests |
| Hash Generation | ✅ Full | Via sync tests |
| Database Migrations | ❌ None | Manual testing |

---

## Version History

**v1.2.1** (2025-11-04)
- Added `test_file_rename.sh` - validates filename_hash updates
- Added `test_file_deletion.sh` - validates cascade cleanup
- Tests verify refactored hash lookup design (removed mapping table)

**v1.2.0** (2025-10-03)
- Initial test suite with OPDS and KOReader API tests
- Deployment automation script
