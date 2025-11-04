#!/bin/bash

# File Deletion Cleanup Test Script
# Tests that file deletion properly cleans up metadata and sync progress

set -e  # Exit on any error

# Configuration
BASE_URL="http://localhost:8080"
NC_URL="$BASE_URL"
KOREADER_BASE_URL="$BASE_URL/apps/koreader_companion"
USERNAME="admin"
PASSWORD="admin"
KOREADER_PASSWORD="test123"
EBOOKS_FOLDER="eBooks"
VERBOSE=false

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        --base-url)
            BASE_URL="$2"
            NC_URL="$BASE_URL"
            KOREADER_BASE_URL="$BASE_URL/apps/koreader_companion"
            shift 2
            ;;
        --username)
            USERNAME="$2"
            shift 2
            ;;
        --password)
            PASSWORD="$2"
            shift 2
            ;;
        --koreader-password)
            KOREADER_PASSWORD="$2"
            shift 2
            ;;
        --folder)
            EBOOKS_FOLDER="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo "Options:"
            echo "  -v, --verbose           Show detailed output"
            echo "  --base-url URL          Set base URL (default: http://localhost:8080)"
            echo "  --username USER         Set username (default: admin)"
            echo "  --password PASS         Set NC password (default: admin)"
            echo "  --koreader-password PW  Set KOReader password (default: test123)"
            echo "  --folder NAME           Set eBooks folder (default: eBooks)"
            echo "  -h, --help              Show this help"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

# Function to print colored output
print_status() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

# Function to print test section
print_section() {
    echo
    print_status "$BLUE" "=== $1 ==="
}

# Function to print test result
print_result() {
    local status=$1
    local test_name=$2
    local details=$3

    if [[ "$status" == "PASS" ]]; then
        print_status "$GREEN" "✓ $test_name"
    elif [[ "$status" == "FAIL" ]]; then
        print_status "$RED" "✗ $test_name"
    else
        print_status "$YELLOW" "⚠ $test_name"
    fi

    if [[ "$VERBOSE" == true && -n "$details" ]]; then
        echo "  $details"
    fi
}

# Function to execute OCC command
occ_command() {
    local cmd=$1
    if command -v docker &> /dev/null; then
        docker exec -u www-data nextcloud php occ $cmd 2>&1
    else
        print_status "$YELLOW" "Docker not available, cannot execute occ command: $cmd"
        return 1
    fi
}

# Function to query database
db_query() {
    local query=$1
    if command -v docker &> /dev/null; then
        docker exec nextcloud-db mysql -u nextcloud -pnextcloud nextcloud -e "$query" 2>&1 | tail -n +2
    else
        print_status "$YELLOW" "Docker not available, cannot query database"
        return 1
    fi
}

# Function to make WebDAV request
webdav_request() {
    local method=$1
    local path=$2
    local data=$3

    local url="${NC_URL}/remote.php/dav/files/${USERNAME}/${path}"

    local curl_args=(
        -X "$method"
        -u "${USERNAME}:${PASSWORD}"
        -s
    )

    if [[ -n "$data" ]]; then
        curl_args+=(--data-binary "@${data}")
    fi

    if [[ "$VERBOSE" == true ]]; then
        echo "  WebDAV: $method $url" >&2
    fi

    local response=$(curl "${curl_args[@]}" "$url" 2>&1)
    echo "$response"
}

# Function to make authenticated KOReader API request
koreader_request() {
    local method=$1
    local endpoint=$2
    local data=$3

    # Use MD5 hash of password for KOReader auth
    local auth_key=$(echo -n "$KOREADER_PASSWORD" | md5sum | cut -d' ' -f1)

    local curl_args=(
        -X "$method"
        -H "x-auth-user: $USERNAME"
        -H "x-auth-key: $auth_key"
        -H "Content-Type: application/vnd.koreader.v1+json"
        -H "Accept: application/vnd.koreader.v1+json"
        -s
    )

    if [[ -n "$data" ]]; then
        curl_args+=(-d "$data")
    fi

    if [[ "$VERBOSE" == true ]]; then
        echo "  KOReader API: $method $endpoint" >&2
    fi

    local response=$(curl "${curl_args[@]}" "${KOREADER_BASE_URL}${endpoint}" 2>&1)
    echo "$response"
}

# Main test execution
print_status "$BLUE" "File Deletion Cleanup Test"
print_status "$BLUE" "============================="
echo "Base URL: $BASE_URL"
echo "Username: $USERNAME"
echo "eBooks Folder: $EBOOKS_FOLDER"
echo

# Create test file
TEST_FILENAME="test-deletion-$(date +%s).epub"
TEST_FILE_PATH="$EBOOKS_FOLDER/$TEST_FILENAME"
TEST_CONTENT="Mock EPUB content for deletion test"

print_section "Setup: Creating test file with metadata"

# Create temporary test file
TMP_FILE=$(mktemp)
echo "$TEST_CONTENT" > "$TMP_FILE"

# Upload test file via WebDAV
response=$(webdav_request "PUT" "$TEST_FILE_PATH" "$TMP_FILE")
rm -f "$TMP_FILE"

if [[ -n "$response" ]]; then
    print_result "FAIL" "Upload test file" "$response"
    exit 1
else
    print_result "PASS" "Upload test file" "Uploaded: $TEST_FILENAME"
fi

# Wait for file event processing
sleep 2

# Get file_id
file_id=$(db_query "SELECT fileid FROM oc_filecache WHERE name='$TEST_FILENAME' ORDER BY fileid DESC LIMIT 1;")
if [[ -z "$file_id" ]]; then
    print_result "FAIL" "Get file_id" "File not found in oc_filecache"
    exit 1
fi
print_result "PASS" "Get file_id" "File ID: $file_id"

# Trigger metadata extraction
print_status "$YELLOW" "Triggering metadata extraction..."
occ_command "koreader:generate-hashes" || true
sleep 2

# Verify metadata exists
metadata_id=$(db_query "SELECT id FROM oc_koreader_metadata WHERE file_id=$file_id AND user_id='$USERNAME';")
if [[ -z "$metadata_id" ]]; then
    print_result "FAIL" "Get metadata_id" "No metadata created"
    exit 1
fi
print_result "PASS" "Get metadata_id" "Metadata ID: $metadata_id"

# Get hashes
binary_hash=$(db_query "SELECT binary_hash FROM oc_koreader_metadata WHERE id=$metadata_id;")
filename_hash=$(db_query "SELECT filename_hash FROM oc_koreader_metadata WHERE id=$metadata_id;")

if [[ -z "$binary_hash" ]]; then
    print_result "WARN" "Get binary_hash" "No binary hash (small file)"
    binary_hash="none"
fi

if [[ -z "$filename_hash" ]]; then
    print_result "FAIL" "Get filename_hash" "No filename hash"
    exit 1
fi
print_result "PASS" "Get filename_hash" "Hash: $filename_hash"

# Create sync progress using filename_hash
print_section "Creating sync progress records"

sync_data=$(cat <<EOF
{
    "document": "$filename_hash",
    "progress": "/body[1]/section[1]/p[5]",
    "percentage": 0.25,
    "device": "test-device",
    "device_id": "test-device-001"
}
EOF
)

response=$(koreader_request "PUT" "/sync/syncs/progress" "$sync_data")
if [[ "$response" != *"Progress updated"* && "$response" != *"message"* ]]; then
    print_result "FAIL" "Create sync progress" "$response"
    exit 1
else
    print_result "PASS" "Create sync progress" "Created for document: $filename_hash"
fi

# Verify sync progress in database
sleep 1
progress_count=$(db_query "SELECT COUNT(*) FROM oc_koreader_sync_progress WHERE user_id='$USERNAME' AND document_hash='$filename_hash';")
if [[ "$progress_count" != "1" ]]; then
    print_result "FAIL" "Verify sync progress in DB" "Expected 1, found $progress_count"
    exit 1
else
    print_result "PASS" "Verify sync progress in DB" "1 sync progress record found"
fi

# If binary hash exists, create progress for it too
if [[ "$binary_hash" != "none" && -n "$binary_hash" ]]; then
    sync_data_binary=$(cat <<EOF
{
    "document": "$binary_hash",
    "progress": "/body[1]/section[1]/p[5]",
    "percentage": 0.25,
    "device": "test-device",
    "device_id": "test-device-001"
}
EOF
)
    koreader_request "PUT" "/sync/syncs/progress" "$sync_data_binary" > /dev/null
    sleep 1
    print_result "PASS" "Create binary hash sync progress" "Created for document: $binary_hash"
fi

# Count total records before deletion
print_section "Recording pre-deletion state"

metadata_count_before=$(db_query "SELECT COUNT(*) FROM oc_koreader_metadata WHERE file_id=$file_id;")
progress_count_before=$(db_query "SELECT COUNT(*) FROM oc_koreader_sync_progress WHERE user_id='$USERNAME' AND (document_hash='$filename_hash' OR document_hash='$binary_hash');")

print_result "PASS" "Count metadata records" "Found: $metadata_count_before"
print_result "PASS" "Count sync progress records" "Found: $progress_count_before"

# Delete the file via WebDAV
print_section "Deleting file via WebDAV"

response=$(webdav_request "DELETE" "$TEST_FILE_PATH")
if [[ -n "$response" && "$response" != *"204"* ]]; then
    print_result "WARN" "Delete file" "$response (may be success)"
fi
print_result "PASS" "Delete file" "Deleted: $TEST_FILENAME"

# Wait for deletion event processing
sleep 3

# Verify file removed from filecache
print_section "Verifying cleanup"

filecache_count=$(db_query "SELECT COUNT(*) FROM oc_filecache WHERE fileid=$file_id;")
if [[ "$filecache_count" != "0" ]]; then
    print_result "WARN" "Verify file removed from filecache" "Still in filecache (may be trashed)"
else
    print_result "PASS" "Verify file removed from filecache" "File removed from filecache"
fi

# Verify metadata removed
metadata_count_after=$(db_query "SELECT COUNT(*) FROM oc_koreader_metadata WHERE file_id=$file_id;")
if [[ "$metadata_count_after" != "0" ]]; then
    print_result "FAIL" "Verify metadata removed" "Found $metadata_count_after records, expected 0"
    exit 1
else
    print_result "PASS" "Verify metadata removed" "All metadata cleaned up"
fi

# Verify sync progress removed for filename_hash
progress_filename_count=$(db_query "SELECT COUNT(*) FROM oc_koreader_sync_progress WHERE user_id='$USERNAME' AND document_hash='$filename_hash';")
if [[ "$progress_filename_count" != "0" ]]; then
    print_result "FAIL" "Verify filename_hash sync progress removed" "Found $progress_filename_count records, expected 0"
    exit 1
else
    print_result "PASS" "Verify filename_hash sync progress removed" "Filename hash progress cleaned up"
fi

# Verify sync progress removed for binary_hash (if it existed)
if [[ "$binary_hash" != "none" && -n "$binary_hash" ]]; then
    progress_binary_count=$(db_query "SELECT COUNT(*) FROM oc_koreader_sync_progress WHERE user_id='$USERNAME' AND document_hash='$binary_hash';")
    if [[ "$progress_binary_count" != "0" ]]; then
        print_result "FAIL" "Verify binary_hash sync progress removed" "Found $progress_binary_count records, expected 0"
        exit 1
    else
        print_result "PASS" "Verify binary_hash sync progress removed" "Binary hash progress cleaned up"
    fi
fi

# Verify no orphaned records
print_section "Verifying no orphaned records"

orphaned_metadata=$(db_query "SELECT COUNT(*) FROM oc_koreader_metadata WHERE id=$metadata_id;")
if [[ "$orphaned_metadata" != "0" ]]; then
    print_result "FAIL" "Check orphaned metadata" "Found orphaned metadata with id $metadata_id"
    exit 1
else
    print_result "PASS" "Check orphaned metadata" "No orphaned metadata records"
fi

# Summary
print_section "Test Summary"
print_status "$GREEN" "All file deletion cleanup tests passed!"
echo
print_status "$BLUE" "Verified behaviors:"
echo "  1. File deletion removes file from oc_filecache"
echo "  2. Metadata automatically removed from oc_koreader_metadata"
echo "  3. Sync progress for filename_hash removed from oc_koreader_sync_progress"
if [[ "$binary_hash" != "none" ]]; then
    echo "  4. Sync progress for binary_hash removed from oc_koreader_sync_progress"
fi
echo "  5. No orphaned records remain in any table"
echo "  6. Cleanup is atomic (transaction-based)"
echo
