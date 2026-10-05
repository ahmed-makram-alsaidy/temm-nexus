# The TEMM Nexus status model — canonical meanings (0.6.0 Phase D, §D9)

This document is the answer to the Phase D directive: *"investigate and
document the canonical meaning of health, attention, journey state, and
connection state."* Every product surface that names a state MUST mean what
this document says it means. Where a surface previously disagreed with this
model, the surface was fixed — not the model.

---

## The four concepts are distinct

| Concept | Stored where | Answers | Values (product labels) |
|---|---|---|---|
| **Journey state** | Derived per stage by `ProjectPulse` from migration telemetry | "Where is this project on its migration journey, and what is the next action?" | Not started · In progress · Ready · Needs attention · Blocked · Complete (`JourneyState`) |
| **Overall project state** | `ProjectPulse::overallState()` — the most severe journey state; stored `health_status = unhealthy` forces BLOCKED | "The single word for the project" | Same enum as journey state |
| **Project health** | `projects.health_status` (`healthy / degraded / unhealthy / unknown`), written only by an explicit health check (`ProjectHealthService`) | "Did the last platform health probe (project DB reachable, project Redis reachable) pass?" | via the status dictionary (`ProductStatus`): Healthy / Degraded / Unhealthy / Unknown |
| **Source connection** | `migration_sources.status` per source | "Is the thing we are migrating FROM connected?" | Connected / Needs review / Not connected (presentation mapping of `ProjectPulse` connect state) |
| **Project database reachability** | Live probe, technical view only | "Can the console open the project's own application database right now?" | Reachable / Unreachable / No result / Unavailable — inside the overview's Technical details disclosure |
| **Attention** | `ProjectPulse::blockers()` + `warnings()` | "What specifically needs a human, and what is the recovery action?" | Human sentence items, translated; shared by Home, Projects index, and Project Overview |

The rule that ended the audit's contradictions (wizard "connection succeeds"
vs index "health unknown" vs tables "unreachable"): **these were never the
same fact, and the UI now never flattens them into one value.**

- The wizard connecting a source changes **source connection** — nothing else.
- `health_status = unknown` means **no health probe has run yet**, not
  "broken". Product surfaces say "No health result yet" (a warning), never a
  raw `unknown` badge on a decision surface.
- The Tables page's "unreachable" is **project database reachability** — a
  probe result, not the source connection and not the journey.

## Ownership (one interpretation layer)

- **`ProjectPulse`** owns per-project journey state, blockers, and warnings.
- **`PlatformPulse`** composes many projects from `ProjectPulse` (batched via
  `useBatchedPulses()` for the Projects index) and owns platform-level
  rollups. It never re-derives a project's state.
- **`ProductStatus`** owns the translation of every stored raw enum
  (`not_deployed`, `unknown`, …) into label + tone. Raw values remain visible
  only in technical-details and audit views.
- **`JourneyState` / `JourneyStage`** own the journey vocabulary.

Home, the Projects index, and Project Overview all read the same services, so
they cannot disagree about the same project (asserted by
`ProjectsExperienceTest::test_home_and_overview_use_the_same_attention_language`).

## Attention semantics (shared with Home)

- BLOCKED outranks NEEDS_ATTENTION everywhere; attention-first ordering is:
  blocked → needs attention → in progress → recently meaningful → others.
- An attention item = specific title + one-line reason + one recovery action
  (deep link from the pulse). Raw SQLSTATE, capability slugs, audit verbs and
  internal codes never appear in the item — they stay in the run/check detail
  views and the Technical details disclosure.
- Attention on Home = attention on the Projects index = attention on Project
  Overview, because all three render `ProjectPulse` output verbatim.

## The Migration journey's six product stages (0.6.0 Phase E, §E12)

The project Migration tab presents ONE journey with SIX stage tabs —
Connect · Analyze · Plan · Sync · Verify · Cutover — over the canonical
SEVEN-stage journey above. The mapping is presentation-only and reads
directly from `ProjectPulse::stageState()` (no second interpretation):

| Product stage tab | Canonical stage(s) | State shown |
|---|---|---|
| Connect | CONNECT | `connectState()` |
| Analyze | ANALYZE | `analyzeState()` |
| Plan | PLAN | `planState()` |
| Sync | MIGRATE + SYNC | the MORE SEVERE of the two |
| Verify | VALIDATE | `validateState()` |
| Cutover | CUTOVER | `cutoverState()` |

The Sync tab merges run progress and live sync; its state is the more
severe of the two canonical states (BLOCKED > NEEDS_ATTENTION >
IN_PROGRESS > READY > COMPLETE), a documented presentation aggregation —
not a new interpretation layer. `ProjectPulse::stageUrl()` routes every
canonical stage to the journey page's matching tab, so Home's
"Continue where you left off", the Overview stepper and the journey page
can never disagree about where a project is (§E22).

## Notes for future phases

- `ProjectOverviewData` telemetry (pulse requests, queue depth, storage) is
  L2/L3 operational data and belongs behind the disclosure. Phase D moved it
  nowhere new — it was already inside the disclosure and stays there.
- The legacy `is_admin` gate on the project Team pages (users/roles/
  permissions) is pre-existing behavior; the Settings hub mirrors destination
  permissions rather than inventing its own.
- `health_status` is only ever written by the explicit "Run health check"
  action (now gated on `projects.manage`). Nothing auto-flips health during
  page renders — renders are read-only by contract (§C15).
