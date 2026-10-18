#!/bin/bash
#
# test_indexing.sh - background indexing / reconcile guarantees
#
# Covers the Tier 2 behaviour that the HTTP suites cannot:
#   A  files:scan'd file is indexed by the occ reconciler + hash mappings exist
#   B  a `pending` row (upload path) is re-parsed and becomes `ok`, edits kept
#   C  a stuck `processing` row at the attempt cap becomes `failed` and stops retrying
#   C2 a `failed` row recovers when the file mtime is newer
#   C3 a `processing` row at the cap recovers when the file mtime is newer
#   D  clearing metadata is rebuilt by the reconciler
#   E  a garbage PDF does not break indexing or the OPDS feed
#
# Usage: ./test_scripts/test_indexing.sh [--root DIR] [--base-url URL]
#
set -uo pipefail

ROOT="${KOREADER_TESTENV:-$HOME/.koreader-testenv}"
BASE_URL="http://127.0.0.1:8090/index.php"
USERNAME="admin"
PASSWORD="admin"

while [[ $# -gt 0 ]]; do
    case $1 in
        --root) ROOT="$2"; shift 2 ;;
        --base-url) BASE_URL="$2"; shift 2 ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PHP="$ROOT/php/php"
NC="$ROOT/nextcloud"
# Bind db_exec.php to this root explicitly; otherwise it silently falls back to
# ~/.koreader-testenv and the SQL would run against the wrong database.
DBEXEC=(env NC_CONFIG="$NC/config/config.php" "$PHP" "$SCRIPT_DIR/db_exec.php")

[[ -x "$PHP" ]] || { echo "test env not found at $ROOT"; exit 1; }

DATA_DIR="$("${DBEXEC[@]}" --config datadirectory)"
BOOKS_DIR="$DATA_DIR/$USERNAME/files/eBooks"
mkdir -p "$BOOKS_DIR"

PASS=0 FAIL=0 WARN=0
pass() { echo -e "\033[0;32m✓ $1\033[0m"; PASS=$((PASS + 1)); }
fail() { echo -e "\033[0;31m✗ $1${2:+ ($2)}\033[0m"; FAIL=$((FAIL + 1)); }
warn() { echo -e "\033[1;33m⚠ $1${2:+ ($2)}\033[0m"; WARN=$((WARN + 1)); }

sql() { "${DBEXEC[@]}" "$1" 2>/dev/null; }
occ() { "$PHP" "$NC/occ" "$@" >/dev/null 2>&1; }
index() { "$PHP" "$NC/occ" koreader:index "$USERNAME" >/tmp/koreader_test_index.log 2>&1; }

fileid_for() { sql "SELECT m.file_id FROM {prefix}koreader_metadata m JOIN {prefix}filecache f ON m.file_id = f.fileid WHERE m.user_id = '$USERNAME' AND f.name = '$1' LIMIT 1"; }
metaid_for() { sql "SELECT m.id FROM {prefix}koreader_metadata m JOIN {prefix}filecache f ON m.file_id = f.fileid WHERE m.user_id = '$USERNAME' AND f.name = '$1' LIMIT 1"; }
field_for() { sql "SELECT $1 FROM {prefix}koreader_metadata WHERE id = $2 LIMIT 1"; }

make_epub() {
    # $1 path, $2 unique title (unique content -> unique binary/filename hashes;
    # the hash_mapping table has a UNIQUE(user_id, document_hash) constraint).
    "$PHP" -r '
      $z = new ZipArchive(); $p = $argv[1]; $title = $argv[2];
      $z->open($p, ZipArchive::CREATE | ZipArchive::OVERWRITE);
      $z->addFromString("mimetype", "application/epub+zip");
      $z->addFromString("META-INF/container.xml", "<?xml version=\"1.0\"?><container version=\"1.0\" xmlns=\"urn:oasis:names:tc:opendocument:xmlns:container\"><rootfiles><rootfile full-path=\"content.opf\" media-type=\"application/oebps-package+xml\"/></rootfiles></container>");
      $z->addFromString("content.opf", "<?xml version=\"1.0\"?><package xmlns=\"http://www.idpf.org/2007/opf\" version=\"2.0\" unique-identifier=\"id\"><metadata xmlns:dc=\"http://purl.org/dc/elements/1.1/\"><dc:title>" . $title . "</dc:title><dc:creator>Tester</dc:creator><dc:language>en</dc:language></metadata><manifest></manifest><spine></spine></package>");
      $z->close();
    ' "$1" "$2"
}

cleanup() {
    rm -f "$BOOKS_DIR"/index-*.epub "$BOOKS_DIR"/index-*.pdf 2>/dev/null
    occ files:scan "$USERNAME"
}
trap cleanup EXIT

echo -e "\033[0;34mIndexing test suite\033[0m ($ROOT)"

# Deterministic starting point (previous runs may have left orphans that would
# collide with the hash_mapping UNIQUE(user_id, document_hash) constraint).
sql "DELETE FROM {prefix}koreader_hash_mapping WHERE user_id = '$USERNAME'"
sql "DELETE FROM {prefix}koreader_metadata WHERE user_id = '$USERNAME'"

# ---------------------------------------------------------------------------
# A: files:scan -> occ index -> metadata + hash mappings
# ---------------------------------------------------------------------------
make_epub "$BOOKS_DIR/index-a.epub" "Index A"
make_epub "$BOOKS_DIR/index-b.epub" "Index B"
make_epub "$BOOKS_DIR/index-c.epub" "Index C"
make_epub "$BOOKS_DIR/index-c2.epub" "Index C2"
make_epub "$BOOKS_DIR/index-c3.epub" "Index C3"
occ files:scan "$USERNAME"
index

aid=$(metaid_for index-a.epub)
if [[ -n "$aid" && "$(field_for status "$aid")" == "ok" ]]; then
    pass "A: files:scan'd EPUB indexed to status=ok"
else
    fail "A: files:scan'd EPUB indexed to status=ok" "status=$(field_for status "${aid:-0}")"
fi

hashcount=$(sql "SELECT COUNT(*) FROM {prefix}koreader_hash_mapping hm JOIN {prefix}koreader_metadata m ON hm.metadata_id = m.id JOIN {prefix}filecache f ON m.file_id = f.fileid WHERE f.name = 'index-a.epub'")
if [[ "${hashcount:-0}" -ge 1 ]]; then
    pass "A: hash mappings created at index time"
else
    fail "A: hash mappings created at index time" "count=${hashcount:-0}"
fi

# ---------------------------------------------------------------------------
# B: pending row is parsed and becomes ok; stored edit is preserved
# ---------------------------------------------------------------------------
bid=$(metaid_for index-b.epub)
sql "UPDATE {prefix}koreader_metadata SET status = 'pending', title = 'Custom B' WHERE id = $bid"
index
if [[ "$(field_for status "$bid")" == "ok" && "$(field_for attempts "$bid")" == "1" && "$(field_for title "$bid")" == "Custom B" ]]; then
    pass "B: pending row re-parsed to ok (attempts=1, edit preserved)"
else
    fail "B: pending row re-parsed to ok" "status=$(field_for status "$bid") attempts=$(field_for attempts "$bid") title=$(field_for title "$bid")"
fi

# ---------------------------------------------------------------------------
# C: stuck processing at the cap becomes failed and is not retried
# ---------------------------------------------------------------------------
cid=$(metaid_for index-c.epub)
sql "UPDATE {prefix}koreader_metadata SET status = 'processing', attempts = 3, updated_at = '2099-01-01 00:00:00' WHERE id = $cid"
index
if [[ "$(field_for status "$cid")" == "failed" ]]; then
    pass "C: stuck processing row becomes failed"
else
    fail "C: stuck processing row becomes failed" "status=$(field_for status "$cid")"
fi
index
if [[ "$(field_for status "$cid")" == "failed" && "$(field_for attempts "$cid")" == "3" ]]; then
    pass "C: failed row is not retried (attempts unchanged)"
else
    fail "C: failed row is not retried" "status=$(field_for status "$cid") attempts=$(field_for attempts "$cid")"
fi

# ---------------------------------------------------------------------------
# C2: failed row recovers when the file changes
# ---------------------------------------------------------------------------
c2id=$(metaid_for index-c2.epub)
sql "UPDATE {prefix}koreader_metadata SET status = 'failed', attempts = 3, failed_at = '2000-01-01 00:00:00' WHERE id = $c2id"
touch "$BOOKS_DIR/index-c2.epub"
occ files:scan "$USERNAME"
index
if [[ "$(field_for status "$c2id")" == "ok" && "$(field_for attempts "$c2id")" == "1" ]]; then
    pass "C2: changed failed file recovers to ok (attempts reset)"
else
    fail "C2: changed failed file recovers to ok" "status=$(field_for status "$c2id") attempts=$(field_for attempts "$c2id")"
fi

# ---------------------------------------------------------------------------
# C3: processing at the cap recovers when the file changes
# ---------------------------------------------------------------------------
c3id=$(metaid_for index-c3.epub)
sql "UPDATE {prefix}koreader_metadata SET status = 'processing', attempts = 3, updated_at = '2000-01-01 00:00:00' WHERE id = $c3id"
touch "$BOOKS_DIR/index-c3.epub"
occ files:scan "$USERNAME"
index
if [[ "$(field_for status "$c3id")" == "ok" && "$(field_for attempts "$c3id")" == "1" ]]; then
    pass "C3: changed capped file recovers to ok (attempts reset)"
else
    fail "C3: changed capped file recovers to ok" "status=$(field_for status "$c3id") attempts=$(field_for attempts "$c3id")"
fi

# ---------------------------------------------------------------------------
# D: clearing metadata is rebuilt by the reconciler
# ---------------------------------------------------------------------------
sql "DELETE FROM {prefix}koreader_hash_mapping WHERE user_id = '$USERNAME'"
sql "DELETE FROM {prefix}koreader_metadata WHERE user_id = '$USERNAME'"
index
rebuilt=$(sql "SELECT COUNT(*) FROM {prefix}koreader_metadata WHERE user_id = '$USERNAME'")
if [[ "${rebuilt:-0}" -ge 5 ]]; then
    pass "D: clear-library metadata is rebuilt ($rebuilt rows)"
else
    fail "D: clear-library metadata is rebuilt" "rows=${rebuilt:-0}"
fi

# ---------------------------------------------------------------------------
# E: garbage PDF does not break indexing or the OPDS feed
# ---------------------------------------------------------------------------
printf '%%PDF-1.4 this is not a real pdf body \x00\x01\x02 garbage' > "$BOOKS_DIR/index-e.pdf"
occ files:scan "$USERNAME"
index
eid=$(metaid_for index-e.pdf)
if [[ -n "$eid" && "$(field_for status "$eid")" == "ok" ]]; then
    pass "E: garbage PDF indexed with filename fallback (status=ok)"
else
    fail "E: garbage PDF indexed with filename fallback" "status=$(field_for status "${eid:-0}")"
fi

opds_status=$(curl -s -o /tmp/index_e_opds.xml -w "%{http_code}" -u "$USERNAME:$PASSWORD" "$BASE_URL/apps/koreader_companion/opds")
if [[ "$opds_status" == "200" ]] && grep -q "index-e" /tmp/index_e_opds.xml; then
    pass "E: OPDS feed still loads and lists the garbage PDF"
else
    fail "E: OPDS feed still loads and lists the garbage PDF" "status=$opds_status"
fi

echo
echo -e "Indexing suite: \033[0;32m${PASS} PASS\033[0m, \033[0;31m${FAIL} FAIL\033[0m, \033[1;33m${WARN} WARN\033[0m"
[[ "$FAIL" -eq 0 ]]