<?php

// ── First-run setup wizard — 0.4.0-rc.5 (Phase 41) ───────────────────

return [

    'brand_line' => ':brand — first-run setup',
    'page_title' => 'Setup — :brand · Step :step/:total',

    'steps' => [
        1 => 'Welcome & System Check',
        2 => 'Platform Identity',
        3 => 'Database Verification',
        4 => 'Redis Verification',
        5 => 'Storage',
        6 => 'Admin Account',
        7 => 'Domain / URL',
        8 => 'Mail (Optional)',
        9 => 'Backup Configuration',
        10 => 'AI Provider (Optional)',
        11 => 'Security Summary',
        12 => 'Complete',
    ],

    'step_progress' => 'Step :step of :total',
    'back' => 'Back',
    'continue' => 'Continue',
    'finish' => 'Finish setup',

    's1_lead' => 'Welcome. This wizard initializes your self-hosted platform: it verifies the stack, creates the first platform owner, and locks itself when done. Nothing here touches your data — existing databases are only verified, never modified.',

    's2_lead' => 'Name your platform. These values brand the admin UI and outbound messages. Defaults are generic and safe to keep.',
    's2_platform_name' => 'Platform name',
    's2_brand_name' => 'Brand name (optional — used in the UI header)',
    's2_support_url' => 'Support URL (optional)',
    's2_default_language' => 'Default platform language',
    's2_default_language_helper' => 'Used for every new user until they pick their own language.',

    's3_lead' => 'Server-side connectivity check against your PostgreSQL instance.',
    's4_lead' => 'Redis backs cache, queues, sessions and rate limiting.',
    's5_lead' => 'Storage holds uploaded project files and private platform state. Persistence is provided by the Docker volume.',

    's6_lead' => 'The first platform owner. There is no default password — choose a strong one (12+ chars with upper & lower case, numbers, symbols). Credentials are stored encrypted until final completion and created exactly once.',
    's6_note' => 'An admin account has been entered for this setup. Re-submitting will replace the pending credentials; the final owner is created only once at completion.',
    's6_your_name' => 'Your name',
    's6_email' => 'Email',
    's6_password' => 'Password',
    's6_confirm' => 'Confirm password',
    's6_note_owner' => 'This account becomes the platform owner with full control.',

    's7_lead' => 'Public address of this installation. Use your domain if one points here; otherwise the server IP works for bootstrap.',
    's7_https_note' => '<strong>HTTPS, honestly:</strong> a trusted TLS certificate requires a real domain with DNS pointing at this server. With only an IP address, the platform runs over plain HTTP — fine for a quick evaluation, not for production data. Configure DNS, then set the domain here (Caddy issues certificates automatically for reachable domains).',
    's7_platform_url' => 'Platform URL',
    's7_url_hint' => 'Should match APP_URL in your .env. Change .env too if you alter the hostname later.',

    's8_lead' => 'Outgoing email is optional. Without it, the platform operates normally — mail-dependent features (password reset emails, notification channels) report <strong>NOT CONFIGURED</strong> instead of failing. You can add SMTP later.',
    's8_smtp_confirmed' => 'SMTP is configured in my .env (MAIL_MAILER/MAIL_HOST/…)',

    's9_lead' => 'Backups protect every project database and the platform database. The Backup Center in the admin UI configures destinations, schedules and retention; the included scripts can stage <code>pg_dump</code> files under the backup volume.',
    's9_note' => 'Recommended before going live: create a backup destination in <em>Backup Center</em>, run one manual backup, and verify a restore. See <code>docs/BACKUP.md</code>.',

    's10_lead' => 'AI is fully optional (bring your own key). Without any provider key the platform works completely — projects, imports, the Migration Center, backups, auth, storage and realtime are unaffected. With a key, the AI Migration Copilot activates.',
    's10_privacy_note' => '<strong>Privacy:</strong> nothing is ever sent to an AI provider unless you configure a provider key AND invoke AI features yourself. Keys are added later in <em>Settings → Nexus AI Settings</em> and stored encrypted at rest. No key ships with this distribution.',
    's10_byok' => 'I plan to add an AI provider key (BYOK)',

    's11_lead' => 'Review the security posture applied by this installation.',
    's11_items' => [
        'No default credentials exist — the owner account is created only through this wizard.',
        'Secrets (Supabase PATs, AI keys, project secrets) are encrypted at rest.',
        'PostgreSQL and Redis are internal-only; ports 80/443 are the public surface.',
        'Login, setup and sensitive endpoints are rate-limited.',
        'No telemetry: nothing leaves this server unless you invoke AI with your own key.',
        'Admin debug output is disabled in production (APP_DEBUG=false).',
    ],
    's11_ack' => 'I understand and accept this configuration for my installation',

    's12_lead' => 'Everything is ready. Completing setup creates the platform owner exactly once, persists the configuration, and locks this wizard permanently.',
    's12_note' => 'Re-running or refreshing cannot duplicate the admin or the configuration. If two clients try to complete simultaneously, exactly one succeeds.',
    's12_no_admin' => 'No admin account has been entered yet — go back to step 6.',

    'complete_title' => 'Setup complete',
    'complete_body' => ':platform is initialized. Sign in with the owner account you created (:owner). This wizard is now locked.',
    'complete_sign_in' => 'Sign in to the admin console',

    'gate_error' => 'Blocking system checks must pass before continuing.',
];
