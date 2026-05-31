#!/usr/bin/env bash
#
# Push a static experiment results payload to Storyblok for demo purposes.
#
# Install blokctl with Composer:
#   composer create-project hi-folks/blokctl
#   cd blokctl
#
# Usage:
#   examples/push-experiment-results.sh <SPACE_ID> <EXPERIMENT_ID> [RESULTS_JSON_FILE]
#
# If RESULTS_JSON_FILE is omitted, the script uses:
#   examples/experiment-results.json
#
# The JSON file must contain the payload expected by:
#   php bin/blokctl experiment:results:push

set -euo pipefail

BLOKCTL="${BLOKCTL:-php bin/blokctl}"
SPACE_ID="${1:-}"
EXPERIMENT_ID="${2:-}"
RESULTS_FILE="${3:-examples/experiment-results.json}"

if [[ -z "$SPACE_ID" || -z "$EXPERIMENT_ID" ]]; then
  echo "Usage: $0 <SPACE_ID> <EXPERIMENT_ID> [RESULTS_JSON_FILE]" >&2
  echo "Example: $0 292651631151485 178826800153745" >&2
  exit 1
fi

if [[ ! -f "$RESULTS_FILE" ]]; then
  echo "Experiment results file not found: $RESULTS_FILE" >&2
  exit 1
fi

echo "Pushing experiment results"
echo "Space ID: $SPACE_ID"
echo "Experiment ID: $EXPERIMENT_ID"
echo "Results file: $RESULTS_FILE"

$BLOKCTL experiment:results:push \
  -S "$SPACE_ID" \
  "$EXPERIMENT_ID" \
  --file="$RESULTS_FILE" \
  --no-interaction
