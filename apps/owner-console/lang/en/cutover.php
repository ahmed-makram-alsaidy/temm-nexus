<?php

// ── Cutover — 0.6.0 Phase E (§E19/§E20) ──────────────────────────────
// The cutover SAFETY MODEL is untouched: gates, evidence, approvals and
// the blocked/why pattern are exactly the accepted model. This file only
// moves the presentation copy (labels, detail sentences, durations) into
// lang/, so nothing user-visible here stays hardcoded English in Arabic.
// Stored plan data (step notes, gate evidence) remains as recorded.

return [

    // Overall readiness (the single answer).
    'overall_blocked_label' => 'Blocked',
    'overall_blocked_detail_one' => 'One gate is blocking this cutover.',
    'overall_blocked_detail' => ':count gates are blocking this cutover.',
    'overall_warning_label' => 'Warning',
    'overall_warning_detail_one' => 'One item needs attention before you switch.',
    'overall_warning_detail' => ':count items need attention before you switch.',
    'overall_ready_label' => 'Ready',
    'overall_ready_detail' => 'Every gate passes. You can proceed when the window opens.',

    // Why you cannot proceed.
    'issue_blocking' => ':section is blocking',
    'issue_unverified' => ':section is not verified',
    'issue_review' => ':section needs review',

    // Gate states (a gate with no evidence reads "Not verified", never green).
    'gate_pass' => 'Pass',
    'gate_not_applicable' => 'Not applicable',
    'gate_blocked' => 'Blocked',
    'gate_warning' => 'Warning',
    'gate_unverified' => 'Not verified',

    // Reconciliation / validation.
    'validation_error_label' => 'Not verified',
    'validation_error_detail' => 'Validation could not be read.',
    'validation_none_label' => 'Not run',
    'validation_none_detail' => 'No validation has been recorded for this project.',
    'validation_failed_label' => 'Failed',
    'validation_failed_detail' => '{1} One of :total checks failed.|[2,*] :failed of :total checks failed.',
    'validation_review_label' => 'Needs review',
    'validation_review_detail' => '{1} One of :total checks needs review.|[2,*] :warned of :total checks need review.',
    'validation_ok_label' => 'Reconciled',
    'validation_ok_detail' => 'All :total checks pass.',

    // Backup (a backup that was never restored is not yet proven).
    'backup_never_label' => 'Never',
    'backup_never_detail' => 'No backup on record. Cutover cannot be reversible without one.',
    'backup_verified_detail' => 'Verified and restore-drilled.',
    'backup_unproven_detail' => 'Not proven: a backup that has not been restored is not yet a rollback plan.',

    // Rollback readiness.
    'rollback_armed' => 'Armed',
    'rollback_no_plan' => 'No plan',
    'rollback_not_armed' => 'Not armed',
    'rollback_detail_none_both' => 'No cutover plan and no verified backup. Rollback is not yet possible.',
    'rollback_detail_no_plan' => 'No cutover plan yet, so no rollback plan has been generated.',
    'rollback_detail_unverified' => 'A rollback plan exists, but the backup behind it is not verified and restore-drilled.',
    'rollback_detail_ready' => 'A verified backup exists and a rollback plan is recorded.',
    'rollback_procedure_none' => 'No procedure recorded.',

    // Final sync readiness (durations render localized — §E20).
    'finalsync_unverified_label' => 'Not verified',
    'finalsync_unverified_detail' => 'Live Sync has not reported, so the final delta cannot be judged.',
    'finalsync_behind_label' => 'Behind',
    'finalsync_behind_detail' => 'The last change was applied :when. A final delta now would be large.',
    'finalsync_catching_label' => 'Catching up',
    'finalsync_catching_detail' => 'Last change applied :when.',
    'finalsync_uptodate_label' => 'Up to date',
    'finalsync_uptodate_detail' => 'The last change was applied :when.',

    // Approval gate labels (the gate tokens are stored identifiers).
    'gate_label_backup_restore_drill' => 'Backup restore drill',
    'gate_label_final_delta' => 'Final delta',
    'gate_label_endpoint_switch' => 'Endpoint switch',
    'gate_label_source_freeze' => 'Source freeze',

    // Ordered cutover plan step labels.
    'step_verify_backup' => 'Verify backup',
    'step_verify_target' => 'Verify target',
    'step_verify_snapshot' => 'Verify snapshot',
    'step_verify_cdc_lag' => 'Verify Live Sync lag',
    'step_source_freeze' => 'Freeze the source',
    'step_final_delta' => 'Apply the final delta',
    'step_reconcile' => 'Reconcile the target',
    'step_endpoint_switch' => 'Switch the endpoint',
    'step_smoke_test' => 'Run smoke checks',
    'step_observe' => 'Observe',
    'step_complete_or_rollback' => 'Complete or roll back',

    // Blade chrome.
    'readiness_overall' => 'Overall readiness',
    'readiness_blocking' => 'Blocking',
    'readiness_blocking_detail' => 'gates stopping the switch',
    'readiness_to_verify' => 'To verify',
    'readiness_to_verify_detail' => 'warnings and unverified gates',
    'readiness_approvals_outstanding' => 'Approvals outstanding',
    'readiness_approvals_of' => 'of :total required',
    'readiness_plan' => 'Plan',
    'readiness_plan_recorded' => 'Recorded',
    'readiness_plan_missing' => 'Not created',
    'readiness_plan_missing_detail' => 'Run preflight to record one',
    'readiness_begin_window' => 'Begin the cutover window',
    'readiness_begin_note' => 'The platform will not change DNS or endpoints. The ordered plan below is for an operator to execute and record.',
    'readiness_locked' => 'Cutover is not available yet',
    'readiness_locked_reason' => 'Resolve the items below first.',
    'readiness_why_blocked' => 'Why you cannot proceed',
    'readiness_gates' => 'Readiness gates',
    'readiness_gates_desc' => 'A gate with no evidence reads “Not verified” rather than green. The platform never guesses readiness.',
    'readiness_detail' => 'Readiness detail',
    'readiness_sync' => 'Live Sync',
    'readiness_reconciliation' => 'Reconciliation',
    'readiness_backup' => 'Backup',
    'readiness_rollback' => 'Rollback',
    'readiness_final_sync' => 'Final sync',
    'readiness_approvals' => 'Human approvals',
    'readiness_approvals_desc' => 'Every production-affecting gate needs an explicit decision. A gate with no record is awaiting, never granted.',
    'readiness_approved_by' => 'Approved by :name',
    'readiness_approved' => 'Approved',
    'readiness_rejected_by' => 'Rejected by :name',
    'readiness_rejected' => 'Rejected',
    'readiness_awaiting' => 'Awaiting a decision',
    'readiness_awaiting_state' => 'Awaiting',
    'readiness_cannot_approve' => 'You can review this screen but your role does not include',
    'readiness_cannot_approve_suffix' => 'so you cannot record decisions here.',
    'readiness_plan_title' => 'Ordered cutover plan',
    'readiness_plan_desc' => 'Steps marked as needing approval are gated. The platform records each step; it does not execute DNS or endpoint changes.',
    'readiness_approval_tag' => 'approval',
    'readiness_advanced' => 'Advanced details',
    'readiness_rollback_procedure' => 'Rollback procedure',
];
