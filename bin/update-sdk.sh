#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_DIR="${ROOT_DIR}/extension/zerokyc/system/library/zerokyc/vendor/zerokyc/zkp-sdk-php"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

REF="${1:-main}"

git clone --depth 1 --branch "${REF}" https://github.com/zerokyc-payments/zkp-sdk-php.git "${TMP_DIR}/sdk"
COMMIT="$(git -C "${TMP_DIR}/sdk" rev-parse HEAD)"

rm -rf "${TARGET_DIR}"
mkdir -p "${TARGET_DIR}"
cp -R "${TMP_DIR}/sdk/src" "${TARGET_DIR}/src"
cp "${TMP_DIR}/sdk/composer.json" "${TMP_DIR}/sdk/LICENSE" "${TMP_DIR}/sdk/README.md" "${TMP_DIR}/sdk/CHANGELOG.md" "${TMP_DIR}/sdk/SECURITY.md" "${TARGET_DIR}/"
echo "${COMMIT}" > "${TARGET_DIR}/COMMIT"

echo "Updated vendored zkp-sdk-php to ${COMMIT}"
