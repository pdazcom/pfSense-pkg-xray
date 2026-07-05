#!/bin/sh

set -e

TESTS_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP="${PHP:-php}"
passed=0
failed=0

for test_file in "$TESTS_DIR"/*_test.php; do
    [ -f "$test_file" ] || continue
    name="$(basename "$test_file")"
    if "$PHP" "$test_file"; then
        passed=$((passed + 1))
    else
        failed=$((failed + 1))
        echo "FAILED: $name"
    fi
done

echo ""
echo "Test files: $((passed + failed)), failed: $failed"
exit $((failed > 0 ? 1 : 0))
