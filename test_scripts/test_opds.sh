#!/bin/bash

# OPDS API Test Script
# Tests all OPDS endpoints with proper authentication

set -e  # Exit on any error

# Configuration
BASE_URL="http://localhost:8080"
OPDS_BASE_URL="$BASE_URL/apps/koreader_companion/opds"
USERNAME="admin"
PASSWORD="admin"
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
            OPDS_BASE_URL="$BASE_URL/apps/koreader_companion/opds"
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
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo "Options:"
            echo "  -v, --verbose     Show detailed output"
            echo "  --base-url URL    Set base URL (default: http://localhost:8080)"
            echo "  --username USER   Set username (default: admin)"
            echo "  --password PASS   Set password (default: admin)"
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

# Function to make authenticated OPDS request
opds_request() {
    local method=$1
    local endpoint=$2
    
    local curl_args=(
        -s
        -X "$method"
        -u "$USERNAME:$PASSWORD"
        -H "Accept: application/atom+xml;profile=opds-catalog"
    )
    
    if [[ "$VERBOSE" == true ]]; then
        echo "Request: $method $OPDS_BASE_URL$endpoint"
        echo "Auth: $USERNAME:$PASSWORD"
    fi
    
    curl "${curl_args[@]}" "$OPDS_BASE_URL$endpoint"
}

# Function to test HTTP status
test_http_status() {
    local method=$1
    local endpoint=$2
    local expected_status=$3
    local auth_user=${4:-$USERNAME}
    local auth_pass=${5:-$PASSWORD}
    
    local curl_args=(
        -s
        -w "%{http_code}"
        -o /dev/null
        -X "$method"
    )
    
    if [[ -n "$auth_user" && -n "$auth_pass" ]]; then
        curl_args+=(-u "$auth_user:$auth_pass")
    fi
    
    local status_code=$(curl "${curl_args[@]}" "$OPDS_BASE_URL$endpoint")
    
    if [[ "$status_code" == "$expected_status" ]]; then
        return 0
    else
        if [[ "$VERBOSE" == true ]]; then
            echo "Expected: $expected_status, Got: $status_code"
        fi
        return 1
    fi
}

# Function to validate OPDS XML
validate_opds_xml() {
    local response=$1
    local test_name=$2
    
    # Check for XML structure
    if [[ "$response" == *"<?xml"* ]] && [[ "$response" == *"<feed"* ]]; then
        # Check for OPDS namespace
        if [[ "$response" == *"http://www.w3.org/2005/Atom"* ]]; then
            print_result "PASS" "$test_name (Valid OPDS XML)"
            return 0
        else
            print_result "WARN" "$test_name (XML but missing OPDS namespace)" "$response"
            return 1
        fi
    else
        print_result "FAIL" "$test_name (Invalid XML or not OPDS)" "$response"
        return 1
    fi
}

# Start tests
print_status "$BLUE" "OPDS API Test Suite"
print_status "$BLUE" "Testing against: $OPDS_BASE_URL"
print_status "$BLUE" "Username: $USERNAME"
echo

# Test 1: Authentication Tests
print_section "Authentication Tests"

# Valid authentication - Root catalog
if test_http_status "GET" "" "200"; then
    print_result "PASS" "Valid authentication (admin:admin)"
    
    # Also validate the response is proper OPDS
    response=$(opds_request "GET" "")
    validate_opds_xml "$response" "Root catalog OPDS format"
else
    print_result "FAIL" "Valid authentication (admin:admin)"
fi

# Invalid authentication - wrong password
if test_http_status "GET" "" "401" "$USERNAME" "wrongpassword"; then
    print_result "PASS" "Invalid authentication (wrong password)"
else
    print_result "FAIL" "Invalid authentication (wrong password)" "Should return 401"
fi

# Invalid authentication - wrong username
if test_http_status "GET" "" "401" "wronguser" "$PASSWORD"; then
    print_result "PASS" "Invalid authentication (wrong username)"
else
    print_result "FAIL" "Invalid authentication (wrong username)" "Should return 401"
fi

# No authentication
status_code=$(curl -s -w "%{http_code}" -o /dev/null "$OPDS_BASE_URL")
if [[ "$status_code" == "401" ]]; then
    print_result "PASS" "No authentication headers"
else
    print_result "FAIL" "No authentication headers" "Should return 401"
fi

# Test 2: Core OPDS Endpoints
print_section "Core OPDS Endpoints"

# Root catalog
response=$(opds_request "GET" "")
validate_opds_xml "$response" "Root catalog"

# OpenSearch descriptor
if test_http_status "GET" "/opensearch.xml" "200"; then
    response=$(opds_request "GET" "/opensearch.xml")
    if [[ "$response" == *"OpenSearchDescription"* ]] && [[ "$response" == *"<?xml"* ]]; then
        print_result "PASS" "OpenSearch descriptor (Valid XML)"
    else
        print_result "FAIL" "OpenSearch descriptor (Invalid format)" "$response"
    fi
else
    print_result "FAIL" "OpenSearch descriptor endpoint"
fi

# Search endpoint (with empty query)
if test_http_status "GET" "/search" "200"; then
    response=$(opds_request "GET" "/search?q=")
    validate_opds_xml "$response" "Search endpoint (empty query)"
else
    print_result "FAIL" "Search endpoint"
fi

# Search endpoint (with query)
if test_http_status "GET" "/search?q=test" "200"; then
    response=$(opds_request "GET" "/search?q=test")
    validate_opds_xml "$response" "Search endpoint (with query)"
else
    print_result "FAIL" "Search endpoint with query"
fi

# Test 3: Faceted Browsing Endpoints
print_section "Faceted Browsing"

faceted_endpoints=(
    "/authors"
    "/series" 
    "/genres"
    "/formats"
    "/languages"
)

for endpoint in "${faceted_endpoints[@]}"; do
    if test_http_status "GET" "$endpoint" "200"; then
        response=$(opds_request "GET" "$endpoint")
        validate_opds_xml "$response" "$(basename $endpoint) catalog"
    else
        print_result "FAIL" "$(basename $endpoint) endpoint"
    fi
done

# Test 4: Content Type Headers
print_section "Content Type Validation"

# Test OPDS endpoints return proper content type
endpoints=("" "/authors" "/series" "/genres" "/search?q=test")
for endpoint in "${endpoints[@]}"; do
    response_headers=$(curl -s -I \
        -u "$USERNAME:$PASSWORD" \
        "$OPDS_BASE_URL$endpoint" 2>/dev/null || echo "")
    
    if [[ "$response_headers" == *"application/atom+xml"* ]]; then
        print_result "PASS" "Content-Type for $(basename ${endpoint:-root})"
    else
        print_result "WARN" "Content-Type for $(basename ${endpoint:-root})" "May not be OPDS compliant"
        if [[ "$VERBOSE" == true ]]; then
            echo "  Headers: $response_headers"
        fi
    fi
done

# Test OpenSearch returns XML content type
response_headers=$(curl -s -I \
    -u "$USERNAME:$PASSWORD" \
    "$OPDS_BASE_URL/opensearch.xml" 2>/dev/null || echo "")

if [[ "$response_headers" == *"application/opensearchdescription+xml"* ]] || [[ "$response_headers" == *"application/xml"* ]]; then
    print_result "PASS" "OpenSearch Content-Type"
else
    print_result "WARN" "OpenSearch Content-Type" "Should be application/opensearchdescription+xml"
fi

# Test 5: Error Handling
print_section "Error Handling Tests"

# Non-existent endpoint
if test_http_status "GET" "/nonexistent" "404"; then
    print_result "PASS" "Non-existent endpoint (404)"
else
    print_result "FAIL" "Non-existent endpoint (should be 404)"
fi

# Invalid search parameters
if test_http_status "GET" "/search" "200"; then
    print_result "PASS" "Search without query parameter"
else
    print_result "WARN" "Search without query parameter" "Some implementations may require q parameter"
fi

# Test 6: Book-specific Endpoints (if books exist)
print_section "Book-specific Endpoints"

# Try to get the root catalog and extract a book ID if available
response=$(opds_request "GET" "")
if [[ "$response" == *'<entry>'* ]]; then
    # Extract first book ID from the OPDS feed (this is a simple regex, may need adjustment)
    book_id=$(echo "$response" | grep -oP 'books/\K[0-9]+' | head -1 || echo "")
    
    if [[ -n "$book_id" ]]; then
        print_result "PASS" "Found book ID for testing: $book_id"
        
        # Test thumbnail endpoint
        if test_http_status "GET" "/books/$book_id/thumb" "200"; then
            print_result "PASS" "Book thumbnail endpoint"
        else
            print_result "WARN" "Book thumbnail endpoint" "May not have thumbnail"
        fi
        
        # Test download endpoints (common formats)
        formats=("epub" "pdf" "mobi")
        for format in "${formats[@]}"; do
            status_code=$(curl -s -w "%{http_code}" -o /dev/null \
                -u "$USERNAME:$PASSWORD" \
                "$OPDS_BASE_URL/books/$book_id/download/$format")
            
            if [[ "$status_code" == "200" ]]; then
                print_result "PASS" "Download $format format"
            elif [[ "$status_code" == "404" ]]; then
                print_result "WARN" "Download $format format (format not available)"
            else
                print_result "FAIL" "Download $format format" "Status: $status_code"
            fi
        done
    else
        print_result "WARN" "No books found in catalog" "Cannot test book-specific endpoints"
    fi
else
    print_result "WARN" "No books in catalog" "Add some books to test book-specific endpoints"
fi

# Test 7: Author/Series/Genre specific browsing (if data exists)
print_section "Specific Category Browsing"

# Try to get authors and test specific author endpoint
response=$(opds_request "GET" "/authors")
if [[ "$response" == *'<entry>'* ]]; then
    # Extract first author name (simple regex)
    author=$(echo "$response" | grep -oP '<title[^>]*>\K[^<]+' | head -1 | sed 's/[^a-zA-Z0-9 ]//g' | sed 's/ /%20/g' || echo "")
    
    if [[ -n "$author" ]]; then
        print_result "PASS" "Found author for testing: $(echo $author | sed 's/%20/ /g')"
        
        if test_http_status "GET" "/authors/$author" "200"; then
            response=$(opds_request "GET" "/authors/$author")
            validate_opds_xml "$response" "Specific author books"
        else
            print_result "WARN" "Specific author endpoint" "May have URL encoding issues"
        fi
    fi
else
    print_result "WARN" "No authors found" "Cannot test specific author browsing"
fi

# Summary
print_section "Test Summary"

# Check if we can access the web UI
web_status=$(curl -s -w "%{http_code}" -o /dev/null "$BASE_URL/apps/koreader_companion/")
if [[ "$web_status" == "200" ]]; then
    print_result "PASS" "Web UI accessible at $BASE_URL/apps/koreader_companion/"
elif [[ "$web_status" == "302" ]] || [[ "$web_status" == "301" ]]; then
    print_result "WARN" "Web UI redirects (may need login)" "Status: $web_status"
else
    print_result "WARN" "Web UI status" "Status: $web_status"
fi

echo
print_status "$BLUE" "Testing complete!"
print_status "$YELLOW" "Next steps:"
echo "1. Add some ebooks to test book-specific endpoints"
echo "2. Test with OPDS reader using: $OPDS_BASE_URL"
echo "3. Check web UI at: $BASE_URL/apps/koreader_companion/"
echo "4. Monitor logs for any issues: docker logs <container-id>"
echo "5. Verify OPDS compliance with: https://validator.opds.io/"
echo