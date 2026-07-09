# AI Sessions collector — how it runs

The "AI Sessions" dashboard tab reads per-user snapshot JSONs from
`/shared/www/tickets/storage/ai-sessions/<user>.json`. Each snapshot is written
by `bin/collect-ai-sessions.py`, which **must run as the target user** because
every user's `~/.claude` directory is mode `0700` — cross-user reads are
impossible, so each user has to collect their own sessions.

## Where it's wired in (automatic, all users)

Every user already runs the shared per-user queue daemon
`/shared/cron/scripts/start-agent-tasks-daemon.py` from their own crontab every
minute. The collector is fired from the top of that daemon
(`fire_ai_session_collector()`), detached and error-isolated, so it runs once a
minute **as each user** with no per-user setup. Because the daemon is a shared
script, editing it once covers everyone (artur, robert, adam, adam_airforce1,
adam_airforce2, …).

~50-120 ms per run — the offset cache (`~/.cache/ai-sessions-offsets.json`)
reads only newly-appended bytes of each session JSONL.

## What counts as an open tab (not an orphan)

A live session is **tab-backed** if either:
- its process is under a live `tmux` pane — classic terminal tabs; or
- its `sessionId` is in `~/.claude/.webde-studio/owned-sessions.json` — the new
  WebDE Studio/Cloud panel keeps tabs as `sdk-cli` sessions with no tmux, so
  this registry is the source of truth for panel tabs.

A session is an **orphan** only when it is live and backed by neither. (The
first cut used tmux alone and false-flagged every Studio-panel tab as an
orphan.)

## Manual fallback (only if a user doesn't run the queue daemon)

```bash
* * * * * /usr/bin/python3 /shared/www/tickets/bin/collect-ai-sessions.py >/dev/null 2>&1
```
</content>
