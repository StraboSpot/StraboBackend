#!/bin/bash
#
# Comprehensive API Test Suite for StraboSpot REST Interface
# Tests both collaborative and non-collaborative access
#
# Usage: ./tests/api_test_runner.sh
#

# Configuration
BASE_URL="http://localhost/db"
OWNER_CREDS="testowner@test.com:testpass123"
COLLAB_CREDS="testcollab@test.com:testpass123"

# Test IDs (using timestamp-based IDs)
PROJECT_ID=1735056001001
DATASET_ID=1735056001002
SPOT_ID=1735056001003

# Tracking
PASS_COUNT=0
FAIL_COUNT=0
RESULTS=""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Helper functions
log_test() {
    echo -e "${YELLOW}TEST:${NC} $1"
}

log_pass() {
    echo -e "${GREEN}PASS:${NC} $1"
    ((PASS_COUNT++))
    RESULTS+="PASS: $1\n"
}

log_fail() {
    echo -e "${RED}FAIL:${NC} $1"
    ((FAIL_COUNT++))
    RESULTS+="FAIL: $1\n"
}

# API call helper
api_call() {
    local method=$1
    local endpoint=$2
    local creds=$3
    local data=$4

    if [ -n "$data" ]; then
        curl -s -X "$method" -u "$creds" -H "Content-Type: application/json" -d "$data" "${BASE_URL}${endpoint}"
    else
        curl -s -X "$method" -u "$creds" "${BASE_URL}${endpoint}"
    fi
}

# Check response contains expected string
check_contains() {
    local response=$1
    local expected=$2
    echo "$response" | grep -q "$expected"
}

# Check response for error
check_no_error() {
    local response=$1
    ! echo "$response" | grep -q '"Error"'
}

# Check HTTP status code
get_http_code() {
    local method=$1
    local endpoint=$2
    local creds=$3
    local data=$4

    if [ -n "$data" ]; then
        curl -s -o /dev/null -w "%{http_code}" -X "$method" -u "$creds" -H "Content-Type: application/json" -d "$data" "${BASE_URL}${endpoint}"
    else
        curl -s -o /dev/null -w "%{http_code}" -X "$method" -u "$creds" "${BASE_URL}${endpoint}"
    fi
}

echo "============================================================"
echo "StraboSpot REST API Comprehensive Test Suite"
echo "============================================================"
echo ""
echo "Test Configuration:"
echo "  Base URL: $BASE_URL"
echo "  Owner: testowner@test.com"
echo "  Collaborator: testcollab@test.com"
echo "  Project ID: $PROJECT_ID"
echo "  Dataset ID: $DATASET_ID"
echo "  Spot ID: $SPOT_ID"
echo ""
echo "============================================================"
echo "SECTION 1: NON-COLLABORATIVE ACCESS (Owner-Only)"
echo "============================================================"
echo ""

# Test 1.1: Owner can read their own project
log_test "1.1 Owner can read their own project"
response=$(api_call GET "/project/$PROJECT_ID" "$OWNER_CREDS")
if check_contains "$response" "Test Owner Project" && check_no_error "$response"; then
    log_pass "Owner can read project"
else
    log_fail "Owner cannot read project: $response"
fi

# Test 1.2: Owner can read their own datasets
log_test "1.2 Owner can read their own datasets"
response=$(api_call GET "/projectdatasets/$PROJECT_ID" "$OWNER_CREDS")
if check_contains "$response" "Owner Dataset 1" && check_no_error "$response"; then
    log_pass "Owner can read datasets"
else
    log_fail "Owner cannot read datasets: $response"
fi

# Test 1.3: Owner can read spots in their dataset
log_test "1.3 Owner can read spots in their dataset"
response=$(api_call GET "/datasetspots/$DATASET_ID" "$OWNER_CREDS")
if check_contains "$response" "Test Spot 1" && check_no_error "$response"; then
    log_pass "Owner can read spots"
else
    log_fail "Owner cannot read spots: $response"
fi

# Test 1.4: Owner can read single spot
log_test "1.4 Owner can read single spot"
response=$(api_call GET "/feature/$SPOT_ID" "$OWNER_CREDS")
if check_contains "$response" "Test Spot 1" && check_no_error "$response"; then
    log_pass "Owner can read single spot"
else
    log_fail "Owner cannot read single spot: $response"
fi

# Test 1.5: Owner can get project timestamp
log_test "1.5 Owner can get project timestamp"
response=$(api_call GET "/projecttimestamp/$PROJECT_ID" "$OWNER_CREDS")
if check_no_error "$response"; then
    log_pass "Owner can get project timestamp"
else
    log_fail "Owner cannot get project timestamp: $response"
fi

# Test 1.6: Owner can get dataset timestamp
log_test "1.6 Owner can get dataset timestamp"
response=$(api_call GET "/datasettimestamp/$DATASET_ID" "$OWNER_CREDS")
if check_no_error "$response"; then
    log_pass "Owner can get dataset timestamp"
else
    log_fail "Owner cannot get dataset timestamp: $response"
fi

# Test 1.7: Non-owner cannot read owner's project
log_test "1.7 Non-owner cannot read owner's project (should return 404)"
http_code=$(get_http_code GET "/project/$PROJECT_ID" "$COLLAB_CREDS")
if [ "$http_code" = "404" ]; then
    log_pass "Non-owner correctly denied access (404)"
else
    log_fail "Non-owner should get 404, got: $http_code"
fi

# Test 1.8: Non-owner cannot read owner's datasets
log_test "1.8 Non-owner cannot read owner's datasets (should return 404)"
http_code=$(get_http_code GET "/projectdatasets/$PROJECT_ID" "$COLLAB_CREDS")
if [ "$http_code" = "404" ]; then
    log_pass "Non-owner correctly denied dataset access (404)"
else
    log_fail "Non-owner should get 404, got: $http_code"
fi

# Test 1.9: Non-owner cannot modify owner's project
log_test "1.9 Non-owner cannot modify owner's project"
http_code=$(get_http_code POST "/project/$PROJECT_ID" "$COLLAB_CREDS" '{"id":'$PROJECT_ID',"name":"Hacked","description":{"project_name":"Hacked"}}')
if [ "$http_code" = "404" ] || [ "$http_code" = "403" ]; then
    log_pass "Non-owner correctly denied modification"
else
    log_fail "Non-owner should be denied modification, got: $http_code"
fi

echo ""
echo "============================================================"
echo "SECTION 2: SETTING UP COLLABORATION"
echo "============================================================"
echo ""

# Get user pkeys
OWNER_PKEY=$(docker exec strabo-postgres psql -U strabodbuser -d strabospot -t -c "SELECT pkey FROM users WHERE email='testowner@test.com';" | tr -d ' ')
COLLAB_PKEY=$(docker exec strabo-postgres psql -U strabodbuser -d strabospot -t -c "SELECT pkey FROM users WHERE email='testcollab@test.com';" | tr -d ' ')

echo "Owner PKEY: $OWNER_PKEY"
echo "Collaborator PKEY: $COLLAB_PKEY"

# Create collaboration record (edit permission, accepted)
log_test "2.1 Setting up collaboration in database"
docker exec strabo-postgres psql -U strabodbuser -d strabospot -c "
INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled)
VALUES ('$PROJECT_ID', $OWNER_PKEY, $COLLAB_PKEY, 'edit', true, false)
ON CONFLICT DO NOTHING;
" > /dev/null 2>&1

if [ $? -eq 0 ]; then
    log_pass "Collaboration record created"
else
    log_fail "Failed to create collaboration record"
fi

echo ""
echo "============================================================"
echo "SECTION 3: COLLABORATIVE ACCESS (Edit Permission)"
echo "============================================================"
echo ""

# Test 3.1: Collaborator can now read project
log_test "3.1 Collaborator can read shared project"
response=$(api_call GET "/project/$PROJECT_ID" "$COLLAB_CREDS")
if check_contains "$response" "Test Owner Project" && check_no_error "$response"; then
    log_pass "Collaborator can read shared project"
else
    log_fail "Collaborator cannot read shared project: $response"
fi

# Test 3.2: Collaborator can read datasets
log_test "3.2 Collaborator can read shared datasets"
response=$(api_call GET "/projectdatasets/$PROJECT_ID" "$COLLAB_CREDS")
if check_contains "$response" "Owner Dataset 1" && check_no_error "$response"; then
    log_pass "Collaborator can read datasets"
else
    log_fail "Collaborator cannot read datasets: $response"
fi

# Test 3.3: Collaborator can read spots
log_test "3.3 Collaborator can read spots in shared dataset"
response=$(api_call GET "/datasetspots/$DATASET_ID" "$COLLAB_CREDS")
if check_contains "$response" "Test Spot 1" && check_no_error "$response"; then
    log_pass "Collaborator can read spots"
else
    log_fail "Collaborator cannot read spots: $response"
fi

# Test 3.4: Collaborator can read single spot
log_test "3.4 Collaborator can read single spot"
response=$(api_call GET "/feature/$SPOT_ID" "$COLLAB_CREDS")
if check_contains "$response" "Test Spot 1" && check_no_error "$response"; then
    log_pass "Collaborator can read single spot"
else
    log_fail "Collaborator cannot read single spot: $response"
fi

# Test 3.5: isOwner flag should be false for collaborator
log_test "3.5 isOwner flag is false for collaborator"
response=$(api_call GET "/project/$PROJECT_ID" "$COLLAB_CREDS")
if check_contains "$response" '"isOwner": false' || check_contains "$response" '"isOwner":false'; then
    log_pass "isOwner correctly set to false"
else
    log_fail "isOwner should be false for collaborator: $response"
fi

# Test 3.6: Collaborator can create their own dataset
COLLAB_DATASET_ID=1735056001010
log_test "3.6 Collaborator can create their own dataset"
response=$(api_call POST "/dataset/$COLLAB_DATASET_ID" "$COLLAB_CREDS" '{"name":"Collaborator Dataset"}')
if check_contains "$response" "Collaborator Dataset" && check_no_error "$response"; then
    log_pass "Collaborator can create dataset"
else
    log_fail "Collaborator cannot create dataset: $response"
fi

# Test 3.7: Collaborator can add their dataset to the shared project
log_test "3.7 Collaborator can add their dataset to shared project"
response=$(api_call POST "/projectdatasets/$PROJECT_ID" "$COLLAB_CREDS" "{\"id\":$COLLAB_DATASET_ID}")
if check_contains "$response" "added to project" && check_no_error "$response"; then
    log_pass "Collaborator can add dataset to project"
else
    log_fail "Collaborator cannot add dataset to project: $response"
fi

# Test 3.8: Collaborator can add spots to their own dataset
COLLAB_SPOT_ID=1735056001011
log_test "3.8 Collaborator can add spots to their own dataset"
response=$(api_call POST "/datasetspots/$COLLAB_DATASET_ID" "$COLLAB_CREDS" '{
    "type": "FeatureCollection",
    "features": [{
        "type": "Feature",
        "geometry": {"type": "Point", "coordinates": [-119.0, 35.0]},
        "properties": {"id": 1735056001011, "name": "Collab Spot 1", "date": "2024-12-24T12:00:00", "modified_timestamp": 1735056002}
    }]
}')
if check_contains "$response" "Collab Spot 1" && check_no_error "$response"; then
    log_pass "Collaborator can add spots to their dataset"
else
    log_fail "Collaborator cannot add spots: $response"
fi

# Test 3.9: Collaborator CANNOT edit owner's dataset
log_test "3.9 Collaborator cannot edit owner's dataset (should be denied)"
response=$(api_call POST "/datasetspots/$DATASET_ID" "$COLLAB_CREDS" '{
    "type": "FeatureCollection",
    "features": [{
        "type": "Feature",
        "geometry": {"type": "Point", "coordinates": [-120.0, 36.0]},
        "properties": {"id": 1735056001099, "name": "Unauthorized Spot", "date": "2024-12-24T12:00:00", "modified_timestamp": 1735056099}
    }]
}')
http_code=$(get_http_code POST "/datasetspots/$DATASET_ID" "$COLLAB_CREDS" '{"type":"FeatureCollection","features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[-120,36]},"properties":{"id":1735056001099,"name":"Unauthorized","date":"2024-12-24T12:00:00","modified_timestamp":1735056099}}]}')
if [ "$http_code" = "403" ]; then
    log_pass "Collaborator correctly denied editing owner's dataset"
else
    log_fail "Collaborator should be denied (403), got: $http_code - $response"
fi

# Test 3.10: Collaborator CANNOT delete owner's dataset
log_test "3.10 Collaborator cannot delete owner's dataset"
http_code=$(get_http_code DELETE "/dataset/$DATASET_ID" "$COLLAB_CREDS")
if [ "$http_code" = "403" ]; then
    log_pass "Collaborator correctly denied deleting owner's dataset"
else
    log_fail "Collaborator should be denied deletion (403), got: $http_code"
fi

# Test 3.11: Owner can still edit their own dataset
log_test "3.11 Owner can still edit their own dataset"
response=$(api_call POST "/datasetspots/$DATASET_ID" "$OWNER_CREDS" '{
    "type": "FeatureCollection",
    "features": [{
        "type": "Feature",
        "geometry": {"type": "Point", "coordinates": [-118.6, 34.3]},
        "properties": {"id": 1735056001004, "name": "Owner Spot 2", "date": "2024-12-24T13:00:00", "modified_timestamp": 1735056003}
    }]
}')
if check_contains "$response" "Owner Spot 2" && check_no_error "$response"; then
    log_pass "Owner can edit their own dataset"
else
    log_fail "Owner cannot edit their own dataset: $response"
fi

# Test 3.12: Owner can edit collaborator's dataset (as project owner)
log_test "3.12 Owner can edit collaborator's dataset"
response=$(api_call POST "/datasetspots/$COLLAB_DATASET_ID" "$OWNER_CREDS" '{
    "type": "FeatureCollection",
    "features": [{
        "type": "Feature",
        "geometry": {"type": "Point", "coordinates": [-119.1, 35.1]},
        "properties": {"id": 1735056001012, "name": "Owner Added Spot", "date": "2024-12-24T14:00:00", "modified_timestamp": 1735056004}
    }]
}')
if check_contains "$response" "Owner Added Spot" && check_no_error "$response"; then
    log_pass "Owner can edit collaborator's dataset"
else
    log_fail "Owner cannot edit collaborator's dataset: $response"
fi

echo ""
echo "============================================================"
echo "SECTION 4: READONLY COLLABORATION"
echo "============================================================"
echo ""

# Change collaboration to readonly
log_test "4.1 Changing collaboration to readonly"
docker exec strabo-postgres psql -U strabodbuser -d strabospot -c "
UPDATE collaborators SET collaboration_level = 'readonly'
WHERE strabo_project_id = '$PROJECT_ID' AND collaborator_user_pkey = $COLLAB_PKEY;
" > /dev/null 2>&1
log_pass "Collaboration changed to readonly"

# Test 4.2: Readonly collaborator can still read project
log_test "4.2 Readonly collaborator can read project"
response=$(api_call GET "/project/$PROJECT_ID" "$COLLAB_CREDS")
if check_contains "$response" "Test Owner Project" && check_no_error "$response"; then
    log_pass "Readonly collaborator can read project"
else
    log_fail "Readonly collaborator cannot read project: $response"
fi

# Test 4.3: Readonly collaborator cannot create new dataset
NEW_DATASET_ID=1735056001020
log_test "4.3 Readonly collaborator cannot add new dataset to project"
http_code=$(get_http_code POST "/projectdatasets/$PROJECT_ID" "$COLLAB_CREDS" "{\"id\":$NEW_DATASET_ID}")
if [ "$http_code" = "403" ]; then
    log_pass "Readonly collaborator correctly denied adding dataset"
else
    log_fail "Readonly collaborator should be denied (403), got: $http_code"
fi

# Test 4.4: Readonly collaborator cannot edit their previously created dataset
log_test "4.4 Readonly collaborator cannot edit their dataset anymore"
http_code=$(get_http_code POST "/datasetspots/$COLLAB_DATASET_ID" "$COLLAB_CREDS" '{"type":"FeatureCollection","features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[-119.2,35.2]},"properties":{"id":1735056001013,"name":"Should Fail","date":"2024-12-24T15:00:00","modified_timestamp":1735056005}}]}')
if [ "$http_code" = "403" ]; then
    log_pass "Readonly collaborator correctly denied editing"
else
    log_fail "Readonly collaborator should be denied (403), got: $http_code"
fi

echo ""
echo "============================================================"
echo "SECTION 5: HALTED COLLABORATION"
echo "============================================================"
echo ""

# Change collaboration back to edit and then disable (halt)
log_test "5.1 Setting up halted collaboration"
docker exec strabo-postgres psql -U strabodbuser -d strabospot -c "
UPDATE collaborators SET collaboration_level = 'edit', disabled = true
WHERE strabo_project_id = '$PROJECT_ID' AND collaborator_user_pkey = $COLLAB_PKEY;
" > /dev/null 2>&1
log_pass "Collaboration halted (disabled)"

# Test 5.2: Halted collaborator can still read project
log_test "5.2 Halted collaborator can still read project"
response=$(api_call GET "/project/$PROJECT_ID" "$COLLAB_CREDS")
if check_contains "$response" "Test Owner Project" && check_no_error "$response"; then
    log_pass "Halted collaborator can read project"
else
    log_fail "Halted collaborator cannot read project: $response"
fi

# Test 5.3: Halted collaborator becomes readonly (cannot edit)
log_test "5.3 Halted collaborator becomes readonly"
http_code=$(get_http_code POST "/datasetspots/$COLLAB_DATASET_ID" "$COLLAB_CREDS" '{"type":"FeatureCollection","features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[-119.3,35.3]},"properties":{"id":1735056001014,"name":"Should Fail","date":"2024-12-24T16:00:00","modified_timestamp":1735056006}}]}')
if [ "$http_code" = "403" ]; then
    log_pass "Halted collaborator correctly denied editing"
else
    log_fail "Halted collaborator should be denied (403), got: $http_code"
fi

# Test 5.4: Owner can now edit ALL datasets (including former collaborator's)
log_test "5.4 Owner can edit all datasets when collaboration is halted"
response=$(api_call POST "/datasetspots/$COLLAB_DATASET_ID" "$OWNER_CREDS" '{
    "type": "FeatureCollection",
    "features": [{
        "type": "Feature",
        "geometry": {"type": "Point", "coordinates": [-119.4, 35.4]},
        "properties": {"id": 1735056001015, "name": "Owner Takes Control", "date": "2024-12-24T17:00:00", "modified_timestamp": 1735056007}
    }]
}')
if check_contains "$response" "Owner Takes Control" && check_no_error "$response"; then
    log_pass "Owner can edit all datasets in halted state"
else
    log_fail "Owner cannot edit all datasets in halted state: $response"
fi

echo ""
echo "============================================================"
echo "SECTION 6: EDGE CASES AND ERROR CONDITIONS"
echo "============================================================"
echo ""

# Test 6.1: Invalid project ID returns 404
log_test "6.1 Invalid project ID returns 404"
http_code=$(get_http_code GET "/project/99999999999999" "$OWNER_CREDS")
if [ "$http_code" = "404" ]; then
    log_pass "Invalid project ID correctly returns 404"
else
    log_fail "Invalid project ID should return 404, got: $http_code"
fi

# Test 6.2: Invalid dataset ID returns 404
log_test "6.2 Invalid dataset ID returns 404"
http_code=$(get_http_code GET "/dataset/99999999999999" "$OWNER_CREDS")
if [ "$http_code" = "404" ]; then
    log_pass "Invalid dataset ID correctly returns 404"
else
    log_fail "Invalid dataset ID should return 404, got: $http_code"
fi

# Test 6.3: Invalid spot ID returns 404
log_test "6.3 Invalid spot ID returns 404"
http_code=$(get_http_code GET "/feature/99999999999999" "$OWNER_CREDS")
if [ "$http_code" = "404" ]; then
    log_pass "Invalid spot ID correctly returns 404"
else
    log_fail "Invalid spot ID should return 404, got: $http_code"
fi

# Test 6.4: Empty credentials return 401
log_test "6.4 Empty credentials return 401"
http_code=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/myprojects")
if [ "$http_code" = "401" ]; then
    log_pass "Empty credentials correctly return 401"
else
    log_fail "Empty credentials should return 401, got: $http_code"
fi

# Test 6.5: Invalid credentials return 401
log_test "6.5 Invalid credentials return 401"
http_code=$(get_http_code GET "/myprojects" "invalid@test.com:wrongpass")
if [ "$http_code" = "401" ]; then
    log_pass "Invalid credentials correctly return 401"
else
    log_fail "Invalid credentials should return 401, got: $http_code"
fi

# Test 6.6: MyProjects returns only user's projects
log_test "6.6 MyProjects returns correct data"
response=$(api_call GET "/myprojects" "$OWNER_CREDS")
if check_contains "$response" "Test Owner Project" && check_no_error "$response"; then
    log_pass "MyProjects returns owner's projects"
else
    log_fail "MyProjects failed: $response"
fi

echo ""
echo "============================================================"
echo "SECTION 7: CLEANUP"
echo "============================================================"
echo ""

# Re-enable collaboration for final state check
docker exec strabo-postgres psql -U strabodbuser -d strabospot -c "
UPDATE collaborators SET disabled = false
WHERE strabo_project_id = '$PROJECT_ID' AND collaborator_user_pkey = $COLLAB_PKEY;
" > /dev/null 2>&1
echo "Collaboration re-enabled for inspection"

echo ""
echo "============================================================"
echo "TEST SUMMARY"
echo "============================================================"
echo ""
echo -e "${GREEN}PASSED:${NC} $PASS_COUNT"
echo -e "${RED}FAILED:${NC} $FAIL_COUNT"
echo ""
echo "Total tests: $((PASS_COUNT + FAIL_COUNT))"
echo ""

if [ $FAIL_COUNT -eq 0 ]; then
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed.${NC}"
    echo ""
    echo "Failed tests:"
    echo -e "$RESULTS" | grep "FAIL:"
    exit 1
fi
