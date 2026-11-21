#!/usr/bin/env bash
# Forced command for the GitHub Actions deploy key. In ~/.ssh/authorized_keys on the server:
#   command="bash /home/ubuntu/SubtitleGenerator/Docker/remote-deploy.sh",restrict ssh-ed25519 AAAA... subgen-github-deploy
# Whatever the client asks to run arrives in SSH_ORIGINAL_COMMAND. Only "deploy <commit id>" is accepted,
# so the key can update SubGen to a commit on main and nothing else (no shell, no forwarding).
set -euo pipefail

read -r action sha extra <<< "${SSH_ORIGINAL_COMMAND:-}"
if [[ "${action:-}" != deploy || ! "${sha:-}" =~ ^[0-9a-f]{40}$ || -n "${extra:-}" ]]; then
  echo "This key can only run: deploy <full commit id>" >&2
  exit 2
fi

cd "$(dirname "$0")/.."

# flock queues a deploy behind one that is still running. Merging the exact commit CI tested
# (fast-forward only) deploys what was tested, and fails loudly if tracked files were changed here.
exec flock /tmp/subtitle-deploy.lock \
  bash -c 'git fetch --quiet origin main && git merge --ff-only "$1" && bash Docker/deploy.sh' _ "$sha"
