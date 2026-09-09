#!/usr/bin/env bash
# Builds and tags the pay-api and pay-watcher Docker images locally, for
# trying a build before pushing a pay-v* tag (release-pay-server.yml runs
# the equivalent build in CI and additionally pushes to the registry).
#
# Usage: scripts/release-pay-server.sh [version]
#   version defaults to the git tag/describe output if omitted.
#
# Requires: docker.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(git -C "$REPO_ROOT" describe --tags --always 2>/dev/null || echo "0.0.0-dev")}"
VERSION="${VERSION#pay-v}"
VERSION="${VERSION#v}"

echo "Building pay-server ${VERSION} images ..."

for image in pay-api pay-watcher; do
  docker build \
    -f "$REPO_ROOT/pay-server/docker/Dockerfile.${image}" \
    -t "elektron-net/${image}:${VERSION}" \
    -t "elektron-net/${image}:latest" \
    "$REPO_ROOT"
  echo "Built elektron-net/${image}:${VERSION}"
done
