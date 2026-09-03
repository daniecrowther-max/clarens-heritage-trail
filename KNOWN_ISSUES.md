# Known Issues

## `tests/test-app-browser.js` — reported intermittent failure

**Status: Open — unverified, not reproduced, not fixed.** (investigated 3 Sep 2026)

**Reported:** an earlier session's report claimed the browser suite "failed
1-in-4 runs" and attributed it to "pre-existing headless-Chrome flakiness" —
without actually verifying that in the session that made the claim. One
concrete instance from that session is on record:

```
ERROR Error: app never became ready at http://localhost:8791/?scenario=ok —
  {"url":"...","partners":0,"sites":0,"hasStart":true}
    at Page.goto (tests/test-app-browser.js:139:11)
0 passed, 1 failed
```

i.e. the splash screen dismissed correctly (`hasStart:true`, so the mock
server was serving the real app), but `PARTNERS`/`SITES` never populated —
the content-feed fetch in `mergeRemoteSites()` never came through.

**Investigation (3 Sep 2026):** ran the suite 12 times back-to-back with no
gap between runs — 8× `node tests/test-app-browser.js` alone, then 4× the
full `tests/run.sh` (matching the exact command that was running when the
one recorded failure above happened) — checking for leftover processes on
the harness's ports (8791 mock API, 9333 Chrome DevTools) immediately after
each run.

- **12/12 runs passed**, 0 failures, 0 errors. The browser suite reported
  the identical **51 passed, 0 failed** every single time.
- **0/12 runs left anything listening** on port 8791 or 9333 afterward. This
  rules out the most likely *deterministic* cause I could think of: both
  `mock-api-server.js` and the Chrome instance `test-app-browser.js` spawns
  use hardcoded ports, and `cleanup()` sends `SIGTERM` to the child
  processes without waiting for them to confirm exit before the script
  returns — in theory, a fast-enough next run could spawn a new mock server
  on the same port while the previous one is still dying, hit `EADDRINUSE`
  (there's no `.on('error')` handler on the server, so that would fail
  silently — `stdio` is `'ignore'`), and leave the old, dying instance as
  the one actually answering requests. This would produce exactly the
  `partners:0, sites:0` signature above. Ran 8 iterations with zero gap
  specifically to try to trigger this. Did not happen.
- Checked `dmesg` and `journalctl -k` for OOM kills or Chrome crashes — none
  found (not just for these runs — none logged at all this boot).
- This machine is a shared desktop, not a dedicated CI runner. At
  investigation time: swap fully exhausted (2.0Gi/2.0Gi used), ~8.6Gi/14Gi
  RAM in use, load average 1.5–1.9, with a regular desktop Chrome session,
  an email client, a VM, and **multiple other concurrent Claude Code
  sessions** all running alongside the test's own headless Chrome instance.
  The suite's readiness polling uses fixed timeout budgets (e.g. 15s to wait
  for `PARTNERS`/`SITES` to populate) that normally resolve in a few hundred
  milliseconds — comfortable margin under ordinary load, but not proven safe
  against a large enough host-level scheduling stall caused by something
  else entirely on the machine.

**Conclusion:** the "1-in-4" rate could not be verified — under conditions
deliberately chosen to surface a race (zero gap between runs, repeated
back-to-back), the suite passed 100% of the time (12/12). No bug in the
test harness itself was found. The most plausible remaining explanation is
transient resource contention from other processes on a shared, heavily
loaded desktop machine — circumstantially supported by the swap/memory
state above, but not proven; nothing ties the one recorded failure to a
specific load spike at that moment. Left **open** rather than "fixed"
because no defect was confirmed to fix. No commit is associated with this
entry.

**If it recurs:** capture the failing run's full log immediately (don't
rerun first), and record `dmesg`/`journalctl` output and `free -h` /
`uptime` at that exact moment. That evidence — tying a specific failure to
specific host conditions — is what this investigation didn't have.
