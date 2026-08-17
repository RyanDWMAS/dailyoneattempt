#!/usr/bin/env bash
#
# Punctually trigger the Verifications Weekly Productivity Report.
#
# GitHub Actions' own `schedule:` cron is best-effort and routinely fires hours
# late. A workflow_dispatch from a stable server cron runs within seconds, so we
# use this script as the reliable "alarm clock": the report still executes in
# GitHub Actions (with all its secrets), this just tells it to start now.
#
# Usage:
#   trigger-report.sh preliminary   # Tina-only review copy, no writes
#   trigger-report.sh final         # full team send + commit
#   trigger-report.sh preview       # Ryan-only test, no writes (use to verify setup)
#
# Auth: a fine-grained GitHub PAT with "Actions: read and write" on the repo.
# Provide it via either:
#   - a 0600 file at $GH_TOKEN_FILE (default /etc/verifications-report/gh_token), or
#   - the GITHUB_TOKEN environment variable.
#
set -euo pipefail

MODE="${1:-final}"
case "$MODE" in
  preliminary|final|preview) ;;
  *) echo "usage: $0 {preliminary|final|preview}" >&2; exit 2 ;;
esac

REPO="RyanDWMAS/dailyoneattempt"
WORKFLOW="verifications-report.yml"
GH_TOKEN_FILE="${GH_TOKEN_FILE:-/etc/verifications-report/gh_token}"

TOKEN="${GITHUB_TOKEN:-}"
if [ -z "$TOKEN" ] && [ -r "$GH_TOKEN_FILE" ]; then
  TOKEN="$(tr -d '[:space:]' < "$GH_TOKEN_FILE")"
fi
if [ -z "$TOKEN" ]; then
  echo "No token found (set GITHUB_TOKEN or populate $GH_TOKEN_FILE)" >&2
  exit 3
fi

# curl -f => non-zero exit on any non-2xx (a successful dispatch returns 204),
# so a failure makes cron notice and email the job owner.
curl -fsS -X POST \
  -H "Accept: application/vnd.github+json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-GitHub-Api-Version: 2022-11-28" \
  "https://api.github.com/repos/${REPO}/actions/workflows/${WORKFLOW}/dispatches" \
  -d "{\"ref\":\"master\",\"inputs\":{\"mode\":\"${MODE}\"}}"

echo "$(date -Is) dispatched verifications report: ${MODE}"
