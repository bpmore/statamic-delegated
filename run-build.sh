#!/usr/bin/env bash
#
# run-build.sh — run Claude Code unattended, riding out usage-limit resets.
#
# There is no built-in "wait for the limit to reset and continue" in Claude Code.
# When a limit is hit the process ends. This script is the outside loop that waits
# and re-invokes it. The durable state lives in PROGRESS.md in the repo, not in the
# Claude session, so a completely fresh run can pick up where the last one stopped.
#
# Usage:
#   ./run-build.sh                          # uses ./PROGRESS.md and ./SPEC.md
#   ./run-build.sh --spec docs/wrapped.md
#   ./run-build.sh --sleep 1200 --max 40
#   ./run-build.sh --continue               # resume the previous session's context
#   ./run-build.sh --dry-run                # print what it would do, run nothing
#
# Run it inside tmux or screen so a disconnect doesn't kill it:
#   tmux new -s build './run-build.sh | tee build.log'

set -uo pipefail   # deliberately NOT -e: a non-zero exit is expected and handled

# ---------------------------------------------------------------- defaults ---
SPEC="SPEC.md"
PROGRESS="PROGRESS.md"
SLEEP=1800                 # 30 min between attempts
MAX_ATTEMPTS=24            # ~12h of wall clock at the default sleep
STALL_LIMIT=3              # consecutive no-progress runs before giving up
PERMISSION_MODE="acceptEdits"
CONTINUE=0
DRY_RUN=0
LOG="build-log.jsonl"

# ------------------------------------------------------------------- args ---
while [[ $# -gt 0 ]]; do
  case "$1" in
    --spec)      SPEC="$2"; shift 2 ;;
    --progress)  PROGRESS="$2"; shift 2 ;;
    --sleep)     SLEEP="$2"; shift 2 ;;
    --max)       MAX_ATTEMPTS="$2"; shift 2 ;;
    --mode)      PERMISSION_MODE="$2"; shift 2 ;;
    --continue)  CONTINUE=1; shift ;;
    --dry-run)   DRY_RUN=1; shift ;;
    -h|--help)   sed -n '2,20p' "$0"; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }

# -------------------------------------------------------------- preflight ---
command -v claude >/dev/null 2>&1 || { log "FATAL: 'claude' not on PATH."; exit 1; }
git rev-parse --git-dir >/dev/null 2>&1 || { log "FATAL: not a git repository."; exit 1; }
[[ -f "$SPEC" ]]     || { log "FATAL: spec not found: $SPEC"; exit 1; }
[[ -f "$PROGRESS" ]] || { log "FATAL: progress file not found: $PROGRESS (copy one from progress/)"; exit 1; }

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$BRANCH" == "main" || "$BRANCH" == "master" ]]; then
  log "WARNING: you are on '$BRANCH'. Unattended runs belong on a feature branch."
  read -r -p "Continue anyway? [y/N] " reply
  [[ "${reply:-n}" =~ ^[Yy]$ ]] || exit 1
fi

PROMPT="Read ${PROGRESS} and ${SPEC}.

Do the NEXT UNFINISHED task in ${PROGRESS} and only that task. Do not run ahead.

Before you stop:
  1. Update ${PROGRESS} — tick the task, and note anything surprising under Notes.
  2. Make sure the repo builds and the test suite passes.
  3. Commit your work with a message naming the task.

If every task is complete, put the line ALL TASKS COMPLETE at the top of ${PROGRESS}."

if [[ $DRY_RUN -eq 1 ]]; then
  log "DRY RUN — would execute:"
  echo "claude -p <prompt> --permission-mode ${PERMISSION_MODE} --output-format json"
  echo; echo "--- prompt ---"; echo "$PROMPT"
  exit 0
fi

# ------------------------------------------------------------------- loop ---
attempt=0
stalls=0
log "Starting. spec=$SPEC progress=$PROGRESS branch=$BRANCH mode=$PERMISSION_MODE sleep=${SLEEP}s"

while [[ $attempt -lt $MAX_ATTEMPTS ]]; do
  attempt=$((attempt + 1))

  before_hash="$(git rev-parse HEAD 2>/dev/null || echo none)"
  before_prog="$(md5sum "$PROGRESS" 2>/dev/null | cut -d' ' -f1 || echo none)"

  log "Attempt ${attempt}/${MAX_ATTEMPTS} — invoking Claude Code."

  if [[ $CONTINUE -eq 1 && $attempt -gt 1 ]]; then
    claude --continue -p "$PROMPT" \
      --permission-mode "$PERMISSION_MODE" --output-format json >>"$LOG" 2>&1
  else
    claude -p "$PROMPT" \
      --permission-mode "$PERMISSION_MODE" --output-format json >>"$LOG" 2>&1
  fi
  exit_code=$?
  log "Claude exited with code ${exit_code}."

  if grep -qi "ALL TASKS COMPLETE" "$PROGRESS" 2>/dev/null; then
    log "PROGRESS.md reports ALL TASKS COMPLETE. Done after ${attempt} attempt(s)."
    exit 0
  fi

  after_hash="$(git rev-parse HEAD 2>/dev/null || echo none)"
  after_prog="$(md5sum "$PROGRESS" 2>/dev/null | cut -d' ' -f1 || echo none)"

  if [[ "$before_hash" == "$after_hash" && "$before_prog" == "$after_prog" ]]; then
    stalls=$((stalls + 1))
    log "No commit and no progress change (stall ${stalls}/${STALL_LIMIT})."
    if [[ $stalls -ge $STALL_LIMIT ]]; then
      log "FATAL: ${STALL_LIMIT} consecutive runs made no progress."
      log "This is a real failure, not a usage limit. Check the tail of ${LOG}."
      exit 1
    fi
  else
    stalls=0
    log "Progress made. HEAD ${before_hash:0:7} -> ${after_hash:0:7}"
  fi

  log "Sleeping ${SLEEP}s before the next attempt."
  sleep "$SLEEP"
done

log "Stopped after ${MAX_ATTEMPTS} attempts without completing. Review ${PROGRESS}."
exit 1
