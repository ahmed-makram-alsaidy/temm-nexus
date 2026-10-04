<?php

/* ── 0.6.0 Phase A — Product UI foundation strings ────────────────────
   The reusable error pattern, empty states, progressive disclosure and
   technical-value treatment. Everything the shell and shared components
   render lives here, in English and Arabic. */

return [

    // ── Error experience (A7): what failed → what to do → technical details.
    'error_title' => 'Something went wrong',
    'error_retry' => 'Try again',
    'error_technical_details' => 'Technical details',
    'error_dismiss' => 'Dismiss',

    // Connection failure (shared project-page state).
    'db_unreachable_title' => 'This project\'s database is not reachable',
    'db_unreachable_body' => 'The project database has not been connected yet. Once the project\'s database credentials are set up, tables and data will appear here.',
    'db_unreachable_next' => 'You can set up the connection from the project\'s Connections page.',

    // Wizard failure (creation journey).
    'wizard_step_failed_title' => 'This step could not be completed',
    'wizard_step_failed_body' => 'Nothing was lost — check the highlighted fields or the step settings, then try again.',

    // ── Empty states (A8).
    'empty_title' => 'Nothing here yet',
    'empty_learn_more' => 'Learn more',

    // ── Progressive disclosure (A9).
    'technical_details' => 'Technical details',
    'advanced' => 'Advanced',
    'learn_more' => 'Learn more',

    // ── Technical values (A10) — visually reserved, always LTR.
    'copy' => 'Copy',
    'copied' => 'Copied',

    // ── Scope labels used by the shell.
    'scope_platform' => 'Platform',
];
