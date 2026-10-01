# Scheduler (control plane)

Per project: defined tasks parsed from `routes/console.php` (labeled static
analysis), `schedule:list` live output, and runtime heartbeat history
(`scheduler_heartbeats`: name, last run, run count). The only action runs
`schedule:run` once (audited) — arbitrary commands can never be scheduled from
the browser.

Lesson encoded in the template: `pulse:check` is a supervised daemon — its
daemon form must never be scheduled (it blocks `schedule:run`, and every task
registered after it starves, including the Phase 26.1D heartbeat). If it is
scheduled at all, use the bounded `pulse:check --once` form, which takes one
snapshot and exits; `horizon:snapshot` is one-shot and fine.

Proof (18J): `schedule:run` executes `demo:heartbeat`, heartbeat row increments.
