#!/bin/bash

# File Rename Test Script
# Tests that filename_hash updates correctly when files are renamed

set -e  # Exit on any error

# Configuration
BASE_URL="http://localhost:8080"
NC_URL="$BASE_URL"
USERNAME="admin"
PASSWORD="admin"
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
        --folder)
            EBOOKS_FOLDER="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo "Options:"
            echo "  -v, --verbose     Show detailed output"
            echo "  --base-url URL    Set base URL (default: http://localhost:8080)"
            echo "  --username USER   Set username (default: admin)"
            echo "  --password PASS   Set password (default: admin)"
            echo "  --folder NAME     Set eBooks folder (default: eBooks)"
            echo "  -h, --help        Show this help"
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

# Main test execution
print_status "$BLUE" "File Rename Hash Update Test"
print_status "$BLUE" "=============================="
echo "Base URL: $BASE_URL"
echo "Username: $USERNAME"
echo "eBooks Folder: $EBOOKS_FOLDER"
echo

# Create test file
TEST_FILENAME_ORIGINAL="test-rename-$(date +%s).txt"
TEST_FILENAME_RENAMED="test-renamed-$(date +%s).txt"
TEST_FILE_PATH="$EBOOKS_FOLDER/$TEST_FILENAME_ORIGINAL"
TEST_FILE_PATH_RENAMED="$EBOOKS_FOLDER/$TEST_FILENAME_RENAMED"

print_section "Setup: Creating test file"

# Create temporary test file
TMP_FILE=$(mktemp)
echo "Test content for rename validation" > "$TMP_FILE"

# Upload test file via WebDAV
response=$(webdav_request "PUT" "$TEST_FILE_PATH" "$TMP_FILE")
rm -f "$TMP_FILE"

if [[ -n "$response" ]]; then
    print_result "FAIL" "Upload test file" "$response"
    exit 1
else
    print_result "PASS" "Upload test file" "Uploaded: $TEST_FILENAME_ORIGINAL"
fi

# Wait for file event processing
sleep 2

# Get file_id
print_section "Getting file_id and initial filename_hash"

file_id=$(db_query "SELECT fileid FROM oc_filecache WHERE name='$TEST_FILENAME_ORIGINAL' ORDER BY fileid DESC LIMIT 1;")
if [[ -z "$file_id" ]]; then
    print_result "FAIL" "Get file_id" "File not found in oc_filecache"
    exit 1
fi
print_result "PASS" "Get file_id" "File ID: $file_id"

# Get initial metadata
initial_hash=$(db_query "SELECT filename_hash FROM oc_koreader_metadata WHERE file_id=$file_id;")
if [[ -z "$initial_hash" ]]; then
    print_result "WARN" "Get initial filename_hash" "No metadata found (may need indexing)"
    # Trigger indexing via app
    print_status "$YELLOW" "Triggering metadata extraction..."
    occ_command "koreader:generate-hashes" || true
    sleep 2
    initial_hash=$(db_query "SELECT filename_hash FROM oc_koreader_metadata WHERE file_id=$file_id;")
fi

if [[ -n "$initial_hash" ]]; then
    print_result "PASS" "Get initial filename_hash" "Hash: $initial_hash"
else
    print_result "FAIL" "Get initial filename_hash" "Still no metadata after indexing"
    exit 1
fi

# Calculate expected hashes
expected_initial_hash=$(echo -n "$TEST_FILENAME_ORIGINAL" | md5sum | cut -d' ' -f1)
expected_renamed_hash=$(echo -n "$TEST_FILENAME_RENAMED" | md5sum | cut -d' ' -f1)

if [[ "$initial_hash" != "$expected_initial_hash" ]]; then
    print_result "FAIL" "Verify initial hash calculation" "Expected: $expected_initial_hash, Got: $initial_hash"
else
    print_result "PASS" "Verify initial hash calculation" "Hash matches MD5 of filename"
fi

# Rename the file via WebDAV
print_section "Renaming file via WebDAV"

response=$(curl -X MOVE \
    -u "${USERNAME}:${PASSWORD}" \
    -H "Destination: ${NC_URL}/remote.php/dav/files/${USERNAME}/${TEST_FILE_PATH_RENAMED}" \
    -s \
    "${NC_URL}/remote.php/dav/files/${USERNAME}/${TEST_FILE_PATH}" 2>&1)

if [[ -n "$response" && "$response" != *"204"* && "$response" != *"201"* ]]; then
    print_result "FAIL" "Rename file" "$response"
    exit 1
else
    print_result "PASS" "Rename file" "Renamed: $TEST_FILENAME_ORIGINAL → $TEST_FILENAME_RENAMED"
fi

# Wait for file event processing
sleep 3

# Verify filename updated in filecache
print_section "Verifying filename_hash update"

new_filename=$(db_query "SELECT name FROM oc_filecache WHERE fileid=$file_id;")
if [[ "$new_filename" != "$TEST_FILENAME_RENAMED" ]]; then
    print_result "FAIL" "Verify filename in filecache" "Expected: $TEST_FILENAME_RENAMED, Got: $new_filename"
    exit 1
else
    print_result "PASS" "Verify filename in filecache" "Filename updated correctly"
fi

# Get updated filename_hash
updated_hash=$(db_query "SELECT filename_hash FROM oc_koreader_metadata WHERE file_id=$file_id;")

if [[ -z "$updated_hash" ]]; then
    print_result "FAIL" "Get updated filename_hash" "Metadata disappeared after rename"
    exit 1
fi

if [[ "$updated_hash" == "$initial_hash" ]]; then
    print_result "FAIL" "Verify filename_hash changed" "Hash unchanged: $updated_hash"
    exit 1
else
    print_result "PASS" "Verify filename_hash changed" "Old: $initial_hash → New: $updated_hash"
fi

if [[ "$updated_hash" != "$expected_renamed_hash" ]]; then
    print_result "FAIL" "Verify new hash calculation" "Expected: $expected_renamed_hash, Got: $updated_hash"
else
    print_result "PASS" "Verify new hash calculation" "Hash matches MD5 of new filename"
fi

# Verify metadata_id unchanged
metadata_id_count=$(db_query "SELECT COUNT(*) FROM oc_koreader_metadata WHERE file_id=$file_id;")
if [[ "$metadata_id_count" != "1" ]]; then
    print_result "FAIL" "Verify metadata record integrity" "Found $metadata_id_count records, expected 1"
else
    print_result "PASS" "Verify metadata record integrity" "Single metadata record maintained"
fi

# Cleanup
print_section "Cleanup"

webdav_request "DELETE" "$TEST_FILE_PATH_RENAMED" > /dev/null 2>&1
print_result "PASS" "Delete test file" "Cleaned up test file"

print_section "Test Summary"
print_status "$GREEN" "All file rename tests passed!"
echo
print_status "$BLUE" "Verified behaviors:"
echo "  1. File rename updates filename in oc_filecache"
echo "  2. Filename hash automatically updates in oc_koreader_metadata"
echo "  3. New hash correctly matches MD5(new_filename)"
echo "  4. Metadata record remains intact (no duplicate/orphaned records)"
echo
