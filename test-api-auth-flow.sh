#!/bin/bash

# Test direct API login and JWT retrieval
# This simulates a mobile app or external API client

BASE_URL="http://localhost:8000"
EMAIL="test@example.com"
PASSWORD="password123"

echo "=== TEST 1: Direct API Login ==="
echo "POST ${BASE_URL}/api/auth/login"
echo ""

RESPONSE=$(curl -s -X POST "${BASE_URL}/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{
    \"email\": \"${EMAIL}\",
    \"password\": \"${PASSWORD}\"
  }")

echo "Response:"
echo "$RESPONSE" | jq .
echo ""

# Extract token from response
ACCESS_TOKEN=$(echo "$RESPONSE" | jq -r '.access_token // empty')

if [ -z "$ACCESS_TOKEN" ]; then
  echo "❌ API login failed - no token received"
  exit 1
fi

echo "✅ API login successful"
echo "Access Token: ${ACCESS_TOKEN:0:50}..."
echo ""

echo "=== TEST 2: Use JWT for Protected API Call ==="
echo "GET ${BASE_URL}/api/auth/me"
echo ""

RESPONSE=$(curl -s -X GET "${BASE_URL}/api/auth/me" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  -H "Accept: application/json")

echo "Response:"
echo "$RESPONSE" | jq .
echo ""

echo "=== TEST 3: Refresh Token ==="
REFRESH_TOKEN=$(curl -s -X POST "${BASE_URL}/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\": \"${EMAIL}\", \"password\": \"${PASSWORD}\"}" \
  | jq -r '.refresh_token')

echo "POST ${BASE_URL}/api/auth/refresh"
RESPONSE=$(curl -s -X POST "${BASE_URL}/api/auth/refresh" \
  -H "Content-Type: application/json" \
  -d "{\"refresh_token\": \"${REFRESH_TOKEN}\"}")

echo "Response:"
echo "$RESPONSE" | jq .
echo ""

echo "=== TEST 4: Logout ==="
echo "POST ${BASE_URL}/api/auth/logout"
curl -s -X POST "${BASE_URL}/api/auth/logout" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}" | jq .
