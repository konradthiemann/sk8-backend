#!/bin/sh
# One-time project setup: git hooks, dev image, dependencies.
set -e
cd "$(dirname "$0")/.."

git config core.hooksPath .githooks
echo "git hooks: .githooks activated"

make build
make composer ARGS="install --no-interaction --no-progress"

if [ ! -f .env.local ]; then
	echo "Hint: create .env.local to override DATABASE_URL etc. for your machine (see README)."
fi
echo "Setup complete. Start with: make up"
