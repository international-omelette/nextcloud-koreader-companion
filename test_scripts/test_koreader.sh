#!/bin/bash

# KOReader Sync API Test Script
# Tests all KOReader sync endpoints with MD5 authentication

set -e  # Exit on any error

# Configuration
BASE_URL="http://localhost:8080"
KOREADER_BASE_URL="$BASE_URL/apps/koreader_companion"
USERNAME="admin"
KOREADER_PASSWORD="test123"
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
            KOREADER_BASE_URL="$BASE_URL/apps/koreader_companion"
            shift 2
            ;;
        --username)
            USERNAME="$2"
            shift 2
            ;;
        --password)
            KOREADER_PASSWORD="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo "Options:"
            echo "  -v, --verbose     Show detailed output"
            echo "  --base-url URL    Set base URL (default: http://localhost:8080)"
            echo "  --username USER   Set username (default: admin)"
            echo "  --password PASS   Set KOReader password (default: test)"
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
    elif [[ "$status" == "WARN" ]]; then
        print_status "$YELLOW" "⚠ $test_name"
    fi
    
    if [[ "$VERBOSE" == true && -n "$details" ]]; then
        echo "  $details"
    fi
}

# Function to make authenticated KOReader API request
koreader_request() {
    local method=$1
    local endpoint=$2
    local data=$3
    
    # Use MD5 hash of password for KOReader auth
    local auth_key=$(echo -n "$KOREADER_PASSWORD" | md5sum | cut -d' ' -f1)
    
    local curl_args=(
        -s
        -X "$method"
        -H "Content-Type: application/vnd.koreader.v1+json"
        -H "x-auth-user: $USERNAME"
        -H "x-auth-key: $auth_key"
    )
    
    if [[ -n "$data" ]]; then
        curl_args+=(-d "$data")
    fi
    
    if [[ "$VERBOSE" == true ]]; then
        echo "Request: $method $KOREADER_BASE_URL$endpoint"
        echo "Headers: x-auth-user: $USERNAME, x-auth-key: $auth_key"
        if [[ -n "$data" ]]; then
            echo "Data: $data"
        fi
    fi
    
    curl "${curl_args[@]}" "$KOREADER_BASE_URL$endpoint"
}

# Function to test HTTP status
test_http_status() {
    local method=$1
    local endpoint=$2
    local data=$3
    local expected_status=$4
    
    local auth_key=$(echo -n "$KOREADER_PASSWORD" | md5sum | cut -d' ' -f1)
    
    local curl_args=(
        -s
        -w "%{http_code}"
        -o /dev/null
        -X "$method"
        -H "Content-Type: application/vnd.koreader.v1+json"
        -H "x-auth-user: $USERNAME"
        -H "x-auth-key: $auth_key"
    )
    
    if [[ -n "$data" ]]; then
        curl_args+=(-d "$data")
    fi
    
    local status_code=$(curl "${curl_args[@]}" "$KOREADER_BASE_URL$endpoint")
    
    if [[ "$status_code" == "$expected_status" ]]; then
        return 0
    else
        if [[ "$VERBOSE" == true ]]; then
            echo "Expected: $expected_status, Got: $status_code"
        fi
        return 1
    fi
}

# Start tests
print_status "$BLUE" "KOReader Sync API Test Suite"
print_status "$BLUE" "Testing against: $KOREADER_BASE_URL"
print_status "$BLUE" "Username: $USERNAME"
echo

# Test 1: Health Check
print_section "Health Check"

response=$(curl -s "$KOREADER_BASE_URL/sync/healthcheck" || echo "ERROR")
if [[ "$response" == *'"state":"OK"'* ]]; then
    print_result "PASS" "Health check endpoint" "$response"
else
    print_result "FAIL" "Health check endpoint" "$response"
fi

# Test 2: Authentication
print_section "Authentication Tests"

# Valid authentication
if test_http_status "GET" "/sync/users/auth" "" "200"; then
    print_result "PASS" "Valid authentication (admin:MD5(test))"
else
    print_result "FAIL" "Valid authentication (admin:MD5(test))"
fi

# Invalid authentication - wrong password
auth_key_wrong=$(echo -n "wrongpass" | md5sum | cut -d' ' -f1)
status_code=$(curl -s -w "%{http_code}" -o /dev/null \
    -H "x-auth-user: $USERNAME" \
    -H "x-auth-key: $auth_key_wrong" \
    "$KOREADER_BASE_URL/sync/users/auth")

if [[ "$status_code" == "401" ]]; then
    print_result "PASS" "Invalid authentication (wrong password)"
else
    print_result "FAIL" "Invalid authentication (wrong password)" "Got status: $status_code"
fi

# Missing headers
status_code=$(curl -s -w "%{http_code}" -o /dev/null "$KOREADER_BASE_URL/sync/users/auth")
if [[ "$status_code" == "401" ]]; then
    print_result "PASS" "No authentication headers"
else
    print_result "FAIL" "No authentication headers" "Got status: $status_code"
fi

# Test 3: Progress Endpoints
print_section "Progress API Tests"

# Test document hash (example hash)
TEST_DOCUMENT_HASH="abcd1234567890abcdef1234567890ab"
TEST_PROGRESS_DATA='{
    "document": "'$TEST_DOCUMENT_HASH'",
    "progress": "/body/DocFragment[2]/div/p[5]",
    "percentage": 0.25,
    "device": "KOReader Test",
    "device_id": "test-device-001"
}'

# Test PUT progress (should work regardless of whether document exists)
response=$(koreader_request "PUT" "/sync/syncs/progress" "$TEST_PROGRESS_DATA")
if [[ "$response" == *'"message"'* ]] && [[ "$response" != *'"error"'* ]]; then
    print_result "PASS" "PUT progress update" "$response"
else
    print_result "WARN" "PUT progress update (document may not exist)" "$response"
fi

# Test GET progress for the document we just updated
response=$(koreader_request "GET" "/sync/syncs/progress/$TEST_DOCUMENT_HASH" "")
if [[ "$response" == *'"document"'* ]] && [[ "$response" == *'"progress"'* ]]; then
    print_result "PASS" "GET progress retrieval" "$response"
elif [[ "$response" == *'"message":"Document not found"'* ]]; then
    print_result "WARN" "GET progress retrieval (document not found - expected if no books indexed)" "$response"
else
    print_result "FAIL" "GET progress retrieval" "$response"
fi

# Test GET progress for non-existent document
NONEXISTENT_HASH="00000000000000000000000000000000"
if test_http_status "GET" "/sync/syncs/progress/$NONEXISTENT_HASH" "" "404"; then
    print_result "PASS" "GET progress for non-existent document (404)"
else
    print_result "FAIL" "GET progress for non-existent document (should be 404)"
fi

# Test 4: Invalid Requests
print_section "Error Handling Tests"

# Invalid JSON in PUT request
invalid_json='{"document": "'$TEST_DOCUMENT_HASH'", "progress":'
response=$(koreader_request "PUT" "/sync/syncs/progress" "$invalid_json")
if [[ "$response" == *'"error"'* ]] || [[ "$response" == *'"message"'* ]]; then
    print_result "PASS" "Invalid JSON handling"
else
    print_result "FAIL" "Invalid JSON handling" "$response"
fi

# Missing required fields
missing_fields='{"document": "'$TEST_DOCUMENT_HASH'"}'
response=$(koreader_request "PUT" "/sync/syncs/progress" "$missing_fields")
if [[ "$response" == *'"message"'* ]]; then
    print_result "PASS" "Missing required fields handling"
else
    print_result "FAIL" "Missing required fields handling" "$response"
fi

# Test 5: Content Type Validation
print_section "Content Type Tests"

# Test request content type flexibility - Wrong content type in request
status_code=$(curl -s -w "%{http_code}" -o /dev/null \
    -X "PUT" \
    -H "Content-Type: application/json" \
    -H "x-auth-user: $USERNAME" \
    -H "x-auth-key: $(echo -n "$KOREADER_PASSWORD" | md5sum | cut -d' ' -f1)" \
    -d "$TEST_PROGRESS_DATA" \
    "$KOREADER_BASE_URL/sync/syncs/progress")

if [[ "$status_code" == "200" ]] || [[ "$status_code" == "202" ]]; then
    print_result "PASS" "Request Content-Type flexibility (accepts application/json)"
else
    print_result "WARN" "Request Content-Type strict (requires vnd.koreader.v1+json)" "Status: $status_code"
fi

# Test response content type compliance - Check server returns KOReader content type
auth_key=$(echo -n "$KOREADER_PASSWORD" | md5sum | cut -d' ' -f1)
response_headers=$(curl -s -I \
    -H "x-auth-user: $USERNAME" \
    -H "x-auth-key: $auth_key" \
    "$KOREADER_BASE_URL/sync/healthcheck")

if [[ "$response_headers" == *"application/vnd.koreader.v1+json"* ]]; then
    print_result "PASS" "Response Content-Type compliance (application/vnd.koreader.v1+json)"
else
    print_result "FAIL" "Response Content-Type compliance" "Expected: application/vnd.koreader.v1+json"
    if [[ "$VERBOSE" == true ]]; then
        echo "  Headers: $response_headers"
    fi
fi

# Test all KOReader endpoints return proper content type
endpoints=("/sync/users/auth" "/sync/healthcheck")
for endpoint in "${endpoints[@]}"; do
    response_headers=$(curl -s -I \
        -H "x-auth-user: $USERNAME" \
        -H "x-auth-key: $(echo -n "$KOREADER_PASSWORD" | md5sum | cut -d' ' -f1)" \
        "$KOREADER_BASE_URL$endpoint" 2>/dev/null || echo "")
    
    if [[ "$response_headers" == *"application/vnd.koreader.v1+json"* ]]; then
        print_result "PASS" "Content-Type for $endpoint"
    else
        print_result "FAIL" "Content-Type for $endpoint" "Missing KOReader content type"
    fi
done

# Summary
print_section "Test Summary"

# Check if we can access the web UI
web_status=$(curl -s -w "%{http_code}" -o /dev/null "$BASE_URL/apps/ebooks_poc/")
if [[ "$web_status" == "200" ]]; then
    print_result "PASS" "Web UI accessible at $BASE_URL/apps/ebooks_poc/"
else
    print_result "WARN" "Web UI may need authentication" "Status: $web_status"
fi

echo
print_status "$BLUE" "Testing complete!"
print_status "$YELLOW" "Next steps:"
echo "1. Ensure books are indexed with hashes using: php occ ebooks:generate-hashes"
echo "2. Test with real KOReader device using server: $KOREADER_BASE_URL"
echo "3. Check web UI at: $BASE_URL/apps/ebooks_poc/"
echo "4. Monitor logs for any issues: docker logs <container-id>"
echo