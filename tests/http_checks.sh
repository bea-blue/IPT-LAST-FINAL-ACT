#!/usr/bin/env bash
# End-to-end HTTP checks for the Nested Forum Lab.
#
# Start the app first (php -S localhost:8000 -t public) with a freshly
# imported database/schema.sql, then run:   bash tests/http_checks.sh
# Works in Git Bash on Windows, macOS and Linux (needs curl and grep).
#
# Each check prints PASS or FAIL. The script registers a new random user,
# so you can run it more than once.

BASE="${BASE:-http://localhost:8000}"
JAR="$(mktemp)"
BODY="$(mktemp)"
PASS=0
FAIL=0
USER="tester_$RANDOM$RANDOM"
PASSWORD="correct-horse-42"

cleanup() { rm -f "$JAR" "$BODY"; }
trap cleanup EXIT

check() { # name expected actual
  if [ "$2" = "$3" ]; then PASS=$((PASS + 1)); echo "PASS  $1 ($3)";
  else FAIL=$((FAIL + 1)); echo "FAIL  $1 (expected $2, got $3)"; fi
}

contains() { # name needle
  if grep -qF -- "$2" "$BODY"; then PASS=$((PASS + 1)); echo "PASS  $1";
  else FAIL=$((FAIL + 1)); echo "FAIL  $1 (missing: $2)"; fi
}

absent() { # name needle
  if grep -qF -- "$2" "$BODY"; then FAIL=$((FAIL + 1)); echo "FAIL  $1 (found: $2)";
  else PASS=$((PASS + 1)); echo "PASS  $1"; fi
}

get()  { curl -s -o "$BODY" -w '%{http_code}' -b "$JAR" -c "$JAR" "$BASE$1"; }
post() { local path="$1"; shift; curl -s -o "$BODY" -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$@" "$BASE$path"; }
token() { grep -o 'name="_token" value="[^"]*"' "$BODY" | head -1 | sed 's/.*value="//; s/"$//'; }
reply_count() { get "/discussions/$1" > /dev/null; grep -o 'Replies ([0-9]*)' "$BODY" | grep -o '[0-9]*'; }

echo "== Reading (guest) =="
check "GET / lists discussions"            200 "$(get /)"
check "GET /discussions/1"                 200 "$(get /discussions/1)"
contains "sample tree reaches Depth 4"     "Depth 4"
contains "guest sees login prompt"         "to reply."
absent  "guest sees no reply form"         'name="parent_id"'
check "missing discussion -> 404"          404 "$(get /discussions/999999)"
check "malformed ID 'abc' -> 400"          400 "$(get /discussions/abc)"
check "malformed ID '0' -> 400"            400 "$(get /discussions/0)"
check "malformed ID '01' -> 400"           400 "$(get /discussions/01)"
check "unknown route -> 404"               404 "$(get /nope)"

echo "== Registration =="
get /register > /dev/null; T="$(token)"
check "register with bad CSRF -> 403"      403 "$(post /register --data-urlencode "_token=wrong" --data-urlencode "username=$USER" --data-urlencode "email=$USER@example.test" --data-urlencode "password=$PASSWORD" --data-urlencode "password_confirmation=$PASSWORD")"
check "register invalid input -> 422"      422 "$(post /register --data-urlencode "_token=$T" --data-urlencode "username=x" --data-urlencode "email=not-an-email" --data-urlencode "password=short" --data-urlencode "password_confirmation=other")"
check "register new user -> 303"           303 "$(post /register --data-urlencode "_token=$T" --data-urlencode "username=$USER" --data-urlencode "email=$USER@example.test" --data-urlencode "password=$PASSWORD" --data-urlencode "password_confirmation=$PASSWORD")"
get /register > /dev/null; T="$(token)"
check "duplicate username -> 422"          422 "$(post /register --data-urlencode "_token=$T" --data-urlencode "username=$USER" --data-urlencode "email=other$RANDOM@example.test" --data-urlencode "password=$PASSWORD" --data-urlencode "password_confirmation=$PASSWORD")"
contains "duplicate message shown"         "already taken"

echo "== Login =="
get /login > /dev/null; T="$(token)"
check "wrong password -> 401"              401 "$(post /login --data-urlencode "_token=$T" --data-urlencode "username=$USER" --data-urlencode "password=wrong-password")"
check "seed user (NULL hash) -> 401"       401 "$(post /login --data-urlencode "_token=$T" --data-urlencode "username=Alice" --data-urlencode "password=anything")"
check "login bad CSRF -> 403"              403 "$(post /login --data-urlencode "_token=bad" --data-urlencode "username=$USER" --data-urlencode "password=$PASSWORD")"
check "login correct -> 303"               303 "$(post /login --data-urlencode "_token=$T" --data-urlencode "username=$USER" --data-urlencode "password=$PASSWORD")"
check "old token rotated after login"      403 "$(post /logout --data-urlencode "_token=$T")"

echo "== Authenticated replies =="
BEFORE="$(reply_count 1)"; T="$(token)"
check "depth-5 reply under comment 7 -> 303" 303 "$(post /discussions/1/comments --data-urlencode "_token=$T" --data-urlencode "parent_id=7" --data-urlencode "body=Depth five reply from $USER")"
check "second top-level comment -> 303"      303 "$(post /discussions/1/comments --data-urlencode "_token=$T" --data-urlencode "parent_id=" --data-urlencode "body=Second top-level comment from $USER")"
check "HTML/script comment -> 303"           303 "$(post /discussions/1/comments --data-urlencode "_token=$T" --data-urlencode "parent_id=5" --data-urlencode "body=<script>alert('xss')</script><b>bold?</b>")"
get /discussions/1 > /dev/null
contains "depth-5 reply rendered"            "Depth 5"
contains "new reply shows author"            "<strong class=\"comment__author\">$USER</strong>"
contains "script is escaped"                 "&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;"
absent  "no raw script tag"                  "<script>alert"
check "reply count grew by 3"                $((BEFORE + 3)) "$(reply_count 1)"

T="$(token)"; BEFORE="$(reply_count 1)"
check "invalid CSRF reply -> 403"            403 "$(post /discussions/1/comments --data-urlencode "_token=forged" --data-urlencode "parent_id=1" --data-urlencode "body=forged")"
check "cross-discussion parent -> 422"       422 "$(post /discussions/1/comments --data-urlencode "_token=$T" --data-urlencode "parent_id=8" --data-urlencode "body=wrong thread")"
check "nonexistent parent -> 422"            422 "$(post /discussions/1/comments --data-urlencode "_token=$T" --data-urlencode "parent_id=999999" --data-urlencode "body=ghost parent")"
check "empty comment -> 422"                 422 "$(post /discussions/1/comments --data-urlencode "_token=$T" --data-urlencode "parent_id=1" --data-urlencode "body=   ")"
LONG="$(printf 'a%.0s' $(seq 1 2001))"
check "2001-char comment -> 422"             422 "$(post /discussions/1/comments --data-urlencode "_token=$T" --data-urlencode "parent_id=1" --data-urlencode "body=$LONG")"
check "reply to missing discussion -> 404"   404 "$(post /discussions/999999/comments --data-urlencode "_token=$T" --data-urlencode "parent_id=" --data-urlencode "body=hello")"
check "no rows created by rejected posts"    "$BEFORE" "$(reply_count 1)"

echo "== Logout and guest writes =="
get /discussions/1 > /dev/null; T="$(token)"
check "GET /logout is not allowed -> 404"    404 "$(get /logout)"
check "POST /logout -> 303"                  303 "$(post /logout --data-urlencode "_token=$T")"
BEFORE="$(reply_count 1)"
check "guest POST top-level -> redirect"     303 "$(post /discussions/1/comments --data-urlencode "_token=$T" --data-urlencode "parent_id=" --data-urlencode "body=guest top-level")"
check "guest POST reply -> redirect"         303 "$(post /discussions/1/comments --data-urlencode "_token=$T" --data-urlencode "parent_id=1" --data-urlencode "body=guest reply")"
check "guest POST with no cookies -> redirect" 303 "$(curl -s -o /dev/null -w '%{http_code}' -X POST --data "parent_id=&body=no-session" "$BASE/discussions/1/comments")"
check "guest posts created no rows"          "$BEFORE" "$(reply_count 1)"

echo
echo "Passed: $PASS   Failed: $FAIL"
[ "$FAIL" -eq 0 ]
