# Scheduler (control plane)

Per project: defined tasks parsed from `routes/console.php` (labeled static
analysis), `schedule:list` live output, and runtime heartbeat history
(`scheduler_heartbeats`: name, last run, run count). The only action runs
`schedule:run` once (audited) — arbitrary commands can never be scheduled from
the browser.

Lesson encoded in the template: `pulse:check` is a supervised daemon, must never
be scheduled (it blocks `schedule:run`); `horizon:snapshot` is one-shot and fine.

Proof (18J): `schedule:run` executes `demo:heartbeat`, heartbeat row increments.
