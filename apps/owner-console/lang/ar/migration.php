<?php

// ── رحلة الترحيل — 0.6.0 المرحلة E (§E12–§E21) ───────────────────────
// تبويب الترحيل في المشروع رحلة واحدة: اتصال → تحليل → خطة → مزامنة →
// تحقّق → تحويل نهائي. حالات المراحل تأتي من ProjectPulse (نموذج الرحلة
// القانوني — بلا طبقة تفسير ثانية). نسخة تحذيرات التحليل هنا أيضًا،
// مشتركة مع معالج المشروع الجديد (§E8).

return [

    'title' => 'الترحيل',

    // المراحل الست للرحلة (تبويبات المراحل).
    'stage_connect' => 'الاتصال',
    'stage_analyze' => 'التحليل',
    'stage_plan' => 'الخطة',
    'stage_sync' => 'المزامنة',
    'stage_verify' => 'التحقّق',
    'stage_cutover' => 'التحويل النهائي',

    'stage_of' => 'المرحلة: :current — :state',

    // ── مرحلة الاتصال (§E14) ──────────────────────────────────────────
    'connect_empty_title' => 'لا يوجد مصدر متصل بعد',
    'connect_empty_body' => 'اتصل بقاعدة البيانات التي تُرحّل منها. تقرأ TEMM منها للقراءة فقط — لن يتغيّر فيها أي شيء أبدًا.',
    'connect_add_source' => 'الاتصال بمصدر',
    'connect_source_heading' => 'اتصال المصدر',
    'connect_destination_heading' => 'الوجهة',
    'connect_destination_managed' => 'تديرها TEMM (:database)',
    'connect_destination_external' => 'PostgreSQL خارجية (:database)',
    'connect_destination_none' => 'لم تُختر بعد',
    'connect_last_test' => 'آخر اختبار اتصال ناجح',
    'connect_last_test_none' => 'لم يُختبر بعد',
    'connect_continue_analyze' => 'المتابعة إلى التحليل',
    'connect_fix_connection' => 'إصلاح الاتصال',
    'connect_source_status' => 'الحالة',
    'connect_source_connector' => 'الموصّل',
    'connect_last_error' => 'فشلت آخر محاولة اتصال. تحقّق من بيانات الاعتماد ثم أعد الاختبار.',

    // نموذج إنشاء مصدر (نفس منطق النطاق في مركز الترحيل).
    'connect_new_source' => 'مصدر جديد',
    'connect_source_name' => 'اسم المصدر',
    'connect_source_name_hint' => 'مثل «قاعدة بيانات الإنتاج».',
    'connect_source_type' => 'النوع',
    'connect_source_host' => 'المستضيف',
    'connect_source_port' => 'المنفذ',
    'connect_source_database' => 'قاعدة البيانات',
    'connect_source_username' => 'اسم المستخدم',
    'connect_source_password_secret' => 'اسم سر كلمة المرور في الخزنة',
    'connect_source_password_secret_hint' => 'لا تلصق كلمة المرور أبدًا — أشر إلى سر في الخزنة.',
    'connect_source_created' => 'أُنشئ المصدر (للقراءة فقط)',
    'connect_cancel' => 'إلغاء',

    // ── مرحلة التحليل (§E15) ──────────────────────────────────────────
    'analyze_empty_title' => 'لا يوجد تحليل بعد',
    'analyze_empty_body' => 'شغّل التحليل لاكتشاف مخطط المصدر ومخاطر الترحيل.',
    'analyze_run' => 'تشغيل التحليل',
    'analyze_rerun' => 'إعادة تشغيل التحليل',
    'analyze_continue_plan' => 'المتابعة إلى الخطة',
    'analyze_completed_frag' => 'اكتمل التحليل — جُرد :count كائنًا.',
    'analyze_failed_title' => 'لم يكتمل التحليل.',
    'analyze_failed_body' => 'لم يتغيّر أي شيء — تحقّق من اتصال المصدر ثم أعد تشغيل التحليل.',
    'analyze_progress_title' => 'التحليل قيد التنفيذ',
    'analyze_stage_preparing' => 'التحضير',
    'analyze_stage_inspecting' => 'فحص المخطط',
    'analyze_stage_classifying' => 'فحص التوافق',
    'analyze_stage_reviewing_risks' => 'مراجعة المخاطر',
    'analyze_stage_running' => 'قيد التنفيذ…',
    'analyze_stage_done' => 'أُنجز',
    'analyze_stage_failed' => 'فشل',
    'analyze_cancel' => 'إلغاء',
    'analyze_cancel_note' => 'الإلغاء آمن — لم يتغيّر أي شيء.',
    'analyze_cancelled_title' => 'أُلغي التحليل.',
    'analyze_blockers_title' => 'مشكلات توافق مانعة',
    'analyze_warnings_title' => 'يحتاج مراجعة',

    // نسخة التحذيرات المشتركة (§E8) — يستخدمها معالج المشروع الجديد أيضًا.
    'counts_tables' => 'جداول',
    'counts_views' => 'عروض',
    'counts_auth' => 'مصادقة',
    'counts_storage' => 'تخزين',
    'counts_realtime' => 'زمن حقيقي',
    'counts_schemas' => 'مخططات',
    'counts_extensions' => 'إضافات',
    'counts_postgres' => 'كائنات الخادم',
    'counts_matviews' => 'عروض مادية',
    'counts_enums' => 'أنواع تعدادية',
    'counts_functions' => 'دوال',
    'counts_triggers' => 'مشغلات',
    'counts_policies' => 'سياسات أمان',
    'counts_edge_functions' => 'دوال حافية',
    'counts_client' => 'اعتماديات العميل',
    'counts_cron' => 'مهام مجدولة',
    'warning_domain_not_present' => 'لا يحتوي هذا المصدر على نطاق :domain',
    'warning_domain_not_present_detail' => 'لا شيء للاستيراد هناك — خطة الترحيل تتجاوزه ببساطة.',
    'warning_postgres' => [
        'large_objects' => 'تعذّر فحص بيانات التعريف للكائنات الكبيرة',
        'extensions' => 'تعذّرت قراءة قائمة الإضافات',
    ],
    'warning_optional_detail' => 'تُرك فحص غير جوهري بسبب صلاحيات المصدر. لا تزال خطة الترحيل تستخدم كل ما قُرئ بنجاح.',

    // ── مرحلة الخطة (§E16) ────────────────────────────────────────────
    'plan_empty_title' => 'لا توجد خطة بعد',
    'plan_empty_body' => 'أنشئ خطة الترحيل من أحدث تحليل — ترتّب النقل حسب الاعتماديات.',
    'plan_generate' => 'إنشاء الخطة',
    'plan_regenerate' => 'إنشاء خطة جديدة',
    'plan_continue_sync' => 'المتابعة إلى المزامنة',
    'plan_heading' => 'ما سيُنقل',
    'plan_stages' => 'خطوات النقل',
    'plan_step' => 'خطوة :n',
    'plan_items_frag' => ':count عنصرًا، مرتّبة حسب الاعتماديات (المصادقة أولًا).',
    'plan_not_moving_title' => 'ما قد لا يُنقل تلقائيًا',
    'plan_not_moving_empty' => 'كل ما وجده التحليل يمكن نقله تلقائيًا.',
    'plan_scope_title' => 'النطاق',
    'plan_created' => 'أُنشئت الخطة: :count عنصرًا.',

    // ── مرحلة المزامنة (§E17) ─────────────────────────────────────────
    'sync_empty_title' => 'لا توجد عمليات ترحيل بعد',
    'sync_empty_body' => 'ابدأ ترحيلك عندما تجهز الخطة. التجربة الجافة لا تكتب شيئًا.',
    'sync_start_run' => 'بدء الترحيل',
    'sync_start_dry_run' => 'تجربة جافة — بلا كتابة، مع تحقق كامل من مسار النقل.',
    'sync_start_real_hint' => 'نقل حقيقي — يكتب في الوجهة الموضحة أعلاه. يُنصح بتجربة جافة أولًا.',
    'sync_mode' => 'الوضع',
    'sync_mode_dry_run' => 'تجربة جافة (بلا كتابة)',
    'sync_mode_rehearsal' => 'تجريبي (وجهة قابلة للإتلاف)',
    'sync_mode_real' => 'نقل حقيقي (هدف حقيقي)',
    'sync_progress' => 'التقدّم',
    'sync_target' => 'الوجهة',
    'sync_source_to_target' => ':source ← :target',
    'sync_last_activity' => 'آخر نشاط',
    'sync_no_runs_hint' => 'أنشئ الخطة أولًا — النقل يتبع الخطة.',
    'sync_runs_history' => 'سجل عمليات النقل',
    'sync_guard_plain' => 'وجهات الإنتاج محمية: تُرفض عمليات النقل إلى مشروع مُعلَّم كإنتاج، وإعادة التعيين المدمّرة تتطلب وجهة مُعلَّمة كقابلة للإتلاف، ولا يمكن أن يكون المصدر والوجهة نفس قاعدة البيانات أبدًا.',
    'sync_started_frag' => 'بدأت العملية: :status.',
    'sync_current_phase' => 'المرحلة الحالية',
    'sync_technical_details' => 'التفاصيل التقنية',
    'sync_target_heading' => 'اتصال الوجهة',
    'sync_run_heading' => 'عمليات النقل',

    // ── مرحلة التحقّق (§E18) — التحقق والجهوزية في مكان واحد ──────────
    'verify_empty_title' => 'لا يوجد ما يُتحقق منه بعد',
    'verify_empty_body' => 'يقارن التحقّق ما نُقل بالمصدر. شغّل عملية نقل أولًا، ثم قيّم الجهوزية هنا.',
    'verify_evaluate' => 'تقييم الآن',
    'verify_passed' => 'ناجح',
    'verify_needs_review' => 'يحتاج مراجعة',
    'verify_blocked' => 'مانع',
    'verify_not_applicable' => 'لا ينطبق',
    'verify_check_evidence' => 'البرهان',
    'verify_check_blocks' => 'يمنع التحويل النهائي للإنتاج',
    'verify_acknowledge' => 'إقرار',
    'verify_summary_frag' => ':green ناجح · :yellow يحتاج مراجعة · :red مانع',

    // ── مرحلة التحويل النهائي (§E19) — نموذج البوابات القائم محفوظ ────
    'cutover_stage_note' => 'يحتفظ التحويل النهائي ببواباته وبراهينه واعتماداته — نموذج الأمان دون تغيير، كمرحلة واحدة من الرحلة.',

    // ── المساعد (§E21) — مساعدة سياقية لا وجهة مستقلة ─────────────────
    'copilot_panel_title' => 'مساعد الترحيل',
    'copilot_panel_hint' => 'اسأل عن هذا التحليل: لماذا شيء ما مانع أو ماذا يعني تحذير.',
    'copilot_explain_blockers' => 'اشرح ما هو مانع',
    'copilot_open' => 'افتح المساعد',
    'copilot_done' => 'أنجز المساعد عمله. انظر سجل العمليات في صفحة المساعد.',

    // ── الأخطاء (§E23) ────────────────────────────────────────────────
    'error_generic_title' => 'تعذّر إتمام هذا الإجراء.',
    'error_generic_body' => 'لم يتغيّر أي شيء. عالج المشكلة أو أعد المحاولة.',
    'error_missing_source' => 'المصدر مفقود — اتصل بمصدر من مرحلة الاتصال أولًا.',
    'error_no_completed_analysis' => 'يلزم تحليل مكتمل أولًا — شغّله من مرحلة التحليل.',
];
