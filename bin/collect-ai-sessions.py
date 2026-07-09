#!/usr/bin/env python3
"""
collect-ai-sessions.py — per-user Claude Code session collector.

Runs AS EACH USER (each ~/.claude is mode 0700, so cross-user reads are
impossible — every user must collect their own sessions). Writes a snapshot
to /shared/www/tickets/storage/ai-sessions/<user>.json which the Laravel
TicketsController::aiSessions() merges across all users.

What it reports for every live claude process and every recently-active
(<24h) session:
  user, pid, session_id, status (live|ended), tab_backed, orphan,
  model, runtime_seconds, started_at, last_active, cwd,
  tokens {input, output, cache_read, cache_write}

Orphan test: a live claude session that is backed by NEITHER an open tmux
pane (classic terminal tabs — PID in a tmux pane's descendant tree) NOR the
WebDE Studio/Cloud panel (sessionId listed in ~/.claude/.webde-studio/
owned-sessions.json — the new panel keeps tabs as sdk-cli sessions with no
tmux). We do NOT trust env vars (stale/inherited) or cwd (orphans + live tabs
share /home/$USER).

Performance: JSONL session logs can be 40+ MB. A byte-offset cache at
~/.cache/ai-sessions-offsets.json remembers, per file, the last consumed
offset + running token totals, so each run only parses newly-appended bytes.
"""

import os
import sys
import re
import json
import glob
import time
import pwd
import subprocess
from datetime import datetime, timezone, timedelta

OUT_DIR = "/shared/www/tickets/storage/ai-sessions"
OFFSET_CACHE = os.path.expanduser("~/.cache/ai-sessions-offsets.json")
PROJECTS_DIR = os.path.expanduser("~/.claude/projects")
# Claude Code maintains an authoritative pid -> session index here: one
# <pid>.json per live session with sessionId, cwd, startedAt, name, kind,
# entrypoint, status. This is the exact PID->session link (no heuristics).
SESSIONS_DIR = os.path.expanduser("~/.claude/sessions")
CLAUDE_PATTERN = "bin/claude|plugin-dir /srv/shared/.claude"
DAY_SECONDS = 24 * 60 * 60


def session_index(pid):
    """Return Claude Code's pid->session record, or None."""
    try:
        with open(os.path.join(SESSIONS_DIR, "%d.json" % pid)) as fh:
            return json.load(fh)
    except Exception:
        return None


def now_utc():
    return datetime.now(timezone.utc)


def iso(dt):
    return dt.astimezone(timezone.utc).isoformat()


def iso_to_epoch(s):
    try:
        return datetime.fromisoformat(s.replace("Z", "+00:00")).timestamp()
    except Exception:
        return None


# Activity classification thresholds (seconds).
ACTIVE_MAX = 30 * 60         # < 30m since last activity -> active
DORMANT_MIN = 6 * 60 * 60    # >= 6h since last activity -> dormant (abandoned)
LOOP_RUNTIME = 8 * 60 * 60   # active AND alive >= 8h    -> looping (sustained burn)


def classify_activity(last_age, runtime_seconds):
    """active | idle | dormant | looping | unknown, from last-activity age."""
    if last_age is None:
        return "unknown"
    if last_age < ACTIVE_MAX:
        if runtime_seconds and runtime_seconds >= LOOP_RUNTIME:
            return "looping"   # still working after 8h+ — the runaway signal
        return "active"
    if last_age >= DORMANT_MIN:
        return "dormant"       # open but no activity for 6h+
    return "idle"


def run(cmd):
    try:
        out = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
        return out.stdout
    except Exception:
        return ""


# ─── tmux tab-backed (KEEP) set ─────────────────────────────────────────
def tmux_panes():
    """{pane_pid: (session_name, attached_bool)} for every tmux pane."""
    out = run(["tmux", "list-panes", "-a", "-F",
               "#{pane_pid}\t#{session_name}\t#{session_attached}"])
    res = {}
    for line in out.splitlines():
        p = line.split("\t")
        if len(p) >= 3 and p[0].isdigit():
            res[int(p[0])] = (p[1], p[2] == "1")
    return res


def tmux_pid_map(pane_info, child_map):
    """Map every descendant pid of a pane to that pane's (session_name, attached)."""
    m = {}
    for root, info in pane_info.items():
        stack = [root]
        while stack:
            x = stack.pop()
            if x in m:
                continue
            m[x] = info
            stack.extend(child_map.get(x, []))
    return m


# ─── tab titles + panel session names (the "attached to" label) ──────────
def load_tab_titles():
    """Map WebDE tab id -> title, from ~/.webde/terminal-tabs/*.json.
    A tmux session name is 'webde_<user>_<host>_<tabid>', so its final segment
    is the tab id we look up here."""
    titles = {}
    for f in glob.glob(os.path.expanduser("~/.webde/terminal-tabs/*.json")):
        try:
            for t in (json.load(open(f)).get("tabs") or []):
                if t.get("id"):
                    titles[t["id"]] = t.get("title")
        except Exception:
            pass
    return titles


def load_studio_names():
    try:
        p = os.path.expanduser("~/.claude/webde-studio-session-names.json")
        return json.load(open(p)) if os.path.exists(p) else {}
    except Exception:
        return {}


def build_child_map():
    """Read /proc once to build a {ppid: [child_pids]} map (fast, no subprocess)."""
    child_map = {}
    for entry in os.listdir("/proc"):
        if not entry.isdigit():
            continue
        try:
            with open(f"/proc/{entry}/stat", "rb") as f:
                data = f.read()
            # ppid is field 4, but comm (field 2) may contain spaces/parens —
            # take everything after the final ')'.
            rest = data[data.rfind(b")") + 2:].split()
            ppid = int(rest[1])
        except Exception:
            continue
        child_map.setdefault(ppid, []).append(int(entry))
    return child_map


def descendants(root_pids, child_map):
    """BFS over the /proc child map to collect every descendant of the roots."""
    seen = set()
    stack = list(root_pids)
    while stack:
        pid = stack.pop()
        if pid in seen:
            continue
        seen.add(pid)
        stack.extend(child_map.get(pid, []))
    return seen


# ─── claude process enumeration ─────────────────────────────────────────
# Infrastructure claude processes that are not conversational sessions.
INFRA_PATTERN = re.compile(r"remote-control|remote-daemon")


def claude_pids(user):
    out = run(["pgrep", "-u", user, "-f", CLAUDE_PATTERN])
    pids = []
    for x in out.split():
        if not x.isdigit():
            continue
        pid = int(x)
        try:
            args = " ".join(proc_cmdline(pid))
        except Exception:
            args = ""
        if INFRA_PATTERN.search(args):
            continue  # remote-control daemon / workers are not sessions
        pids.append(pid)
    return pids


def proc_cmdline(pid):
    with open(f"/proc/{pid}/cmdline", "rb") as f:
        return [p.decode("utf-8", "replace") for p in f.read().split(b"\0") if p]


def resume_uuid(parts):
    if "--resume" in parts:
        i = parts.index("--resume")
        if i + 1 < len(parts):
            v = parts[i + 1]
            if re.match(r"^[0-9a-fA-F-]{8,}$", v):
                return v
    return None


def proc_stats_batch(pids):
    """Return {pid: (etimes_seconds, start_dt, cputime_str)} via a single ps call."""
    result = {}
    if not pids:
        return result
    out = run(["ps", "-o", "pid=,etimes=,cputime=", "-p", ",".join(str(p) for p in pids)])
    now = now_utc()
    for line in out.splitlines():
        m = re.match(r"^\s*(\d+)\s+(\d+)\s+(\S+)\s*$", line)
        if not m:
            continue
        pid = int(m.group(1))
        etimes = int(m.group(2))
        result[pid] = (etimes, now - timedelta(seconds=etimes), m.group(3))
    return result


def proc_cwd(pid):
    try:
        return os.readlink(f"/proc/{pid}/cwd")
    except Exception:
        return None


# ─── project dir mapping ────────────────────────────────────────────────
def project_dir_for_cwd(cwd):
    """Claude stores sessions under ~/.claude/projects/<cwd with / and . -> ->."""
    if not cwd:
        return None
    name = re.sub(r"[/.]", "-", cwd)
    return os.path.join(PROJECTS_DIR, name)


# ─── offset cache ───────────────────────────────────────────────────────
def load_offset_cache():
    try:
        with open(OFFSET_CACHE) as f:
            return json.load(f)
    except Exception:
        return {}


def save_offset_cache(cache):
    try:
        os.makedirs(os.path.dirname(OFFSET_CACHE), exist_ok=True)
        tmp = OFFSET_CACHE + ".tmp"
        with open(tmp, "w") as f:
            json.dump(cache, f)
        os.replace(tmp, OFFSET_CACHE)
    except Exception:
        pass


EMPTY_TOK = {"input": 0, "output": 0, "cache_read": 0, "cache_write": 0}


def _accumulate_line(line, tok, state):
    try:
        o = json.loads(line)
    except Exception:
        return
    msg = o.get("message") or {}
    u = msg.get("usage") or o.get("usage")
    if isinstance(u, dict):
        tok["input"] += u.get("input_tokens") or 0
        tok["output"] += u.get("output_tokens") or 0
        tok["cache_read"] += u.get("cache_read_input_tokens") or 0
        tok["cache_write"] += u.get("cache_creation_input_tokens") or 0
    m = msg.get("model") or o.get("model")
    if m:
        state["model"] = m
    ts = o.get("timestamp")
    if ts:
        state["last_active"] = ts


def read_session_tokens(path, cache):
    """
    Parse a JSONL session file incrementally using the byte-offset cache.
    Returns (tokens_dict, model, last_active_iso). Mutates `cache`.
    """
    try:
        size = os.path.getsize(path)
    except Exception:
        return dict(EMPTY_TOK), None, None

    entry = cache.get(path)
    if entry and entry.get("size", -1) <= size and "tok" in entry:
        # File only grew (or unchanged) — parse just the delta from last offset.
        tok = dict(entry["tok"])
        state = {"model": entry.get("model"), "last_active": entry.get("last_active")}
        start = entry.get("offset", 0)
        if start > size:
            start = 0
            tok = dict(EMPTY_TOK)
            state = {"model": None, "last_active": None}
    else:
        # New file or it shrank/rotated — parse from the top.
        tok = dict(EMPTY_TOK)
        state = {"model": None, "last_active": None}
        start = 0

    new_offset = start
    if size > start:
        try:
            with open(path, "rb") as f:
                f.seek(start)
                buf = f.read(size - start)
            last_nl = buf.rfind(b"\n")
            if last_nl >= 0:
                complete = buf[: last_nl + 1]
                new_offset = start + last_nl + 1
                for raw in complete.split(b"\n"):
                    if raw.strip():
                        _accumulate_line(raw.decode("utf-8", "replace"), tok, state)
        except Exception:
            pass

    cache[path] = {
        "size": size,
        "offset": new_offset,
        "tok": tok,
        "model": state["model"],
        "last_active": state["last_active"],
    }
    return tok, state["model"], state["last_active"]


def file_mtime_iso(path):
    try:
        return iso(datetime.fromtimestamp(os.path.getmtime(path), timezone.utc))
    except Exception:
        return None


# The WebDE Studio/Cloud panel keeps its open tabs as sdk-cli sessions that are
# NOT wrapped in tmux — it records the sessionIds it owns here. A session listed
# here is an open panel tab and must NOT be treated as an orphan, even though no
# tmux pane backs it.
OWNED_SESSIONS = os.path.expanduser("~/.claude/.webde-studio/owned-sessions.json")


def load_owned_sessions():
    try:
        with open(OWNED_SESSIONS) as fh:
            data = json.load(fh)
        return set(data) if isinstance(data, list) else set()
    except Exception:
        return set()


# ─── main ───────────────────────────────────────────────────────────────
def collect():
    user = pwd.getpwuid(os.getuid()).pw_name
    cache = load_offset_cache()
    sessions = []

    # 1. tab-backed descendant set (single /proc scan, no per-pid subprocess).
    #    Also map each pid to its tmux session (name + attached) for the
    #    "attached to" label.
    try:
        child_map = build_child_map()
        pane_info = tmux_panes()
        pid_tmux = tmux_pid_map(pane_info, child_map)   # pid -> (session_name, attached)
        keep = set(pid_tmux.keys())
    except Exception:
        pid_tmux = {}
        keep = set()

    # Open tabs of the new Studio/Cloud panel (sdk-cli sessions, no tmux).
    owned = load_owned_sessions()
    tab_titles = load_tab_titles()
    studio_names = load_studio_names()
    coll_now = time.time()

    # 2. live claude processes
    live_pids = claude_pids(user)
    claimed_files = set()  # jsonl paths already mapped to a live PID this run
    proj_listing = {}      # memoised {project_dir: [jsonl paths, mtime desc]}
    live_by_session = {}   # sessionId -> row (dedupes multi-PID sessions)

    stats = proc_stats_batch(live_pids)

    # Resolve each PID's cmdline/uuid/cwd up front so we can process in two
    # passes: --resume PIDs FIRST (they own their exact session file), then
    # non-resume PIDs (best-effort). Doing resume first is what prevents a
    # non-resume orphan from stealing the interactive session's file — many
    # sessions share cwd /home/$USER, so ordering is the only safeguard.
    proc_info = []
    for pid in live_pids:
        etimes, start_dt, cputime = stats.get(pid, (None, None, None))
        idx = session_index(pid) or {}
        try:
            parts = proc_cmdline(pid)
        except Exception:
            parts = []
        # sessionId from the authoritative pid index; --resume arg is a fallback.
        uuid = idx.get("sessionId") or resume_uuid(parts)
        proc_info.append({
            "pid": pid,
            "etimes": etimes if etimes is not None else 0,
            "start_dt": start_dt,
            "cputime": cputime,
            "uuid": uuid,
            "indexed": bool(idx.get("sessionId")),
            "cwd": idx.get("cwd") or proc_cwd(pid),
            "name": idx.get("name"),
            "kind": idx.get("kind"),
            "entrypoint": idx.get("entrypoint"),
            "sess_status": idx.get("status"),
        })
    # indexed/uuid-backed first; within each group, oldest (largest etimes) first.
    proc_info.sort(key=lambda x: (x["uuid"] is None, -x["etimes"]))

    # Only attribute an un-tracked (non --resume) jsonl to a live process when
    # that file is being actively appended right now — a live session writes to
    # its own log continuously. Older files belong to ended sessions and must
    # not be mis-attributed to a live PID (they are counted once in pass 3).
    live_mtime_cutoff = time.time() - 120

    for info in proc_info:
        try:
            pid = info["pid"]
            etimes = info["etimes"]
            start_dt = info["start_dt"]
            cputime = info["cputime"]
            uuid = info["uuid"]
            cwd = info["cwd"]
            proj = project_dir_for_cwd(cwd)

            jsonl_path = None
            if uuid and proj:
                cand = os.path.join(proj, uuid + ".jsonl")
                # sessionId (from the pid index or --resume) is authoritative.
                if os.path.exists(cand) and cand not in claimed_files:
                    jsonl_path = cand
            if jsonl_path is None and uuid is None and proj and os.path.isdir(proj):
                # Best-effort: newest UNCLAIMED, actively-written jsonl in the
                # project dir. Memoised (mtime desc) per dir for this run.
                if proj not in proj_listing:
                    entries = []
                    for c in glob.glob(os.path.join(proj, "*.jsonl")):
                        try:
                            entries.append((os.path.getmtime(c), c))
                        except Exception:
                            pass
                    entries.sort(reverse=True)
                    proj_listing[proj] = entries
                for mt, c in proj_listing[proj]:
                    if c not in claimed_files and mt >= live_mtime_cutoff:
                        jsonl_path = c
                        break
                # else: leave tokens unknown rather than guess wrong.

            tokens = None  # unknown — no session log could be attributed
            model = None
            last_active = None
            if jsonl_path:
                claimed_files.add(jsonl_path)
                if uuid is None:
                    m = re.match(r"^([0-9a-fA-F-]{8,})\.jsonl$", os.path.basename(jsonl_path))
                    if m:
                        uuid = m.group(1)
                tokens, model, last_active = read_session_tokens(jsonl_path, cache)

            # Drop processes that are not identifiable sessions (no sessionId
            # from the index and no --resume uuid) — helper/bridge procs, not
            # conversations worth listing.
            if not uuid:
                continue

            # Tab-backed if a live tmux pane owns the process (classic terminal
            # tab) OR the Studio/Cloud panel lists the session (new panel tab).
            tmux_backed = pid in keep
            studio_backed = bool(uuid) and uuid in owned
            tab_backed = tmux_backed or studio_backed
            tab_source = "tmux" if tmux_backed else ("studio" if studio_backed else None)

            # "Attached to" label: tmux tab title (+ attached/detached), or the
            # Studio panel (+ any panel name), plus the project (cwd).
            attached_label = None
            attached_state = None
            if tmux_backed:
                sname, is_att = pid_tmux.get(pid, (None, False))
                tab_id = sname.rsplit("_", 1)[-1] if sname else None
                attached_label = (tab_titles.get(tab_id) or sname or "terminal tab")
                attached_state = "attached" if is_att else "detached"
            elif studio_backed:
                attached_label = studio_names.get(uuid) or "Studio panel"
                attached_state = "panel"

            # Activity: how fresh is the last log line vs runtime.
            last_age = None
            if last_active:
                e = iso_to_epoch(last_active)
                if e:
                    last_age = int(coll_now - e)
            activity = classify_activity(last_age, etimes)

            row = {
                "pid": pid,
                "session_id": uuid,
                "status": "live",
                "tab_backed": tab_backed,
                "tab_source": tab_source,
                "attached_label": attached_label,
                "attached_state": attached_state,
                "project": cwd,
                "activity": activity,
                "last_active_age_seconds": last_age,
                "orphan": (not tab_backed),
                "name": info.get("name"),
                "kind": info.get("kind"),
                "entrypoint": info.get("entrypoint"),
                "session_status": info.get("sess_status"),
                "model": model,
                "runtime_seconds": etimes,
                "started_at": iso(start_dt) if start_dt else None,
                "last_active": last_active or (iso(start_dt) if start_dt else None),
                "cwd": cwd,
                "cputime": cputime,
                "tokens": tokens,
            }
            # Dedupe by sessionId: one conversation can have several live PIDs
            # (original + a resumed/duplicate process). Collapse into one row —
            # the session is tab-backed if ANY of its PIDs is under a live tab
            # (prevents a stale duplicate from showing as a phantom orphan), and
            # we keep the richest record (tokens known, longest runtime).
            prev = live_by_session.get(uuid)
            if prev is None:
                live_by_session[uuid] = row
            else:
                merged_tab = prev["tab_backed"] or row["tab_backed"]
                merged_source = prev["tab_source"] or row["tab_source"]
                # prefer the row that actually resolved tokens; else longer runtime
                if prev["tokens"] is None and row["tokens"] is not None:
                    keeper = row
                elif row["tokens"] is None and prev["tokens"] is not None:
                    keeper = prev
                else:
                    keeper = prev if prev["runtime_seconds"] >= row["runtime_seconds"] else row
                keeper["tab_backed"] = merged_tab
                keeper["tab_source"] = merged_source
                keeper["orphan"] = not merged_tab
                live_by_session[uuid] = keeper
        except Exception:
            continue

    sessions.extend(live_by_session.values())

    # 3. ended sessions: jsonl mtime < 24h not mapped to a live PID
    cutoff = time.time() - DAY_SECONDS
    if os.path.isdir(PROJECTS_DIR):
        for path in glob.glob(os.path.join(PROJECTS_DIR, "**", "*.jsonl"), recursive=True):
            try:
                if path in claimed_files:
                    continue
                mt = os.path.getmtime(path)
                if mt < cutoff:
                    continue
                tokens, model, last_active = read_session_tokens(path, cache)
                uuid = None
                m = re.match(r"^([0-9a-fA-F-]{8,})\.jsonl$", os.path.basename(path))
                if m:
                    uuid = m.group(1)
                la = last_active or file_mtime_iso(path)
                la_epoch = iso_to_epoch(la) if la else None
                sessions.append({
                    "pid": None,
                    "session_id": uuid,
                    "status": "ended",
                    "tab_backed": False,
                    "tab_source": None,
                    "attached_label": None,
                    "attached_state": None,
                    "project": None,
                    "activity": "ended",
                    "last_active_age_seconds": int(coll_now - la_epoch) if la_epoch else None,
                    "orphan": False,
                    "model": model,
                    "runtime_seconds": None,
                    "started_at": None,
                    "last_active": la,
                    "cwd": None,
                    "cputime": None,
                    "tokens": tokens,
                })
            except Exception:
                continue

    save_offset_cache(cache)

    out = {
        "user": user,
        "collected_at": iso(now_utc()),
        "sessions": sessions,
    }
    write_output(user, out)
    return out


def write_output(user, out):
    try:
        os.makedirs(OUT_DIR, exist_ok=True)
    except Exception:
        pass
    path = os.path.join(OUT_DIR, f"{user}.json")
    tmp = path + f".tmp.{os.getpid()}"
    try:
        with open(tmp, "w") as f:
            json.dump(out, f, indent=2)
        os.replace(tmp, path)
        try:
            os.chmod(path, 0o664)
        except Exception:
            pass
        try:
            subprocess.run(["chgrp", "webde", path], capture_output=True, timeout=5)
        except Exception:
            pass
    except Exception as e:
        sys.stderr.write(f"write failed: {e}\n")


if __name__ == "__main__":
    try:
        collect()
    except Exception as e:
        sys.stderr.write(f"collect failed: {e}\n")
        sys.exit(1)
