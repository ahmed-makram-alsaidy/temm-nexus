<?php

// ── تجربة المشاريع — 0.6.0 المرحلة D (تدقيق §D1–§D11) ────────────────
// كل ما يراه المستخدم في فهرس المشاريع ونظرة المشروع العامة يمر من هذا
// الملف (وبتأزّمه الإنجليزي). لا يُسمح بنص إنجليزي مكتوب داخل هذه
// الأسطح: عبارات المشاكل وتفاصيل المراحل وكلمات الحالة كلها هنا.

return [

    // ── الفهرس: معلومات الترويسة ────────────────────────────────────────
    'count_projects' => '{1} مشروع واحد|{2} مشروعان|[3,10] :count مشاريع|[11,*] :count مشروعًا',
    'count_needs_attention' => '{1} واحد يحتاج انتباهًا|{2} اثنان يحتاجان انتباهًا|[3,10] :count مشاريع تحتاج انتباهًا|[11,*] :count مشروعًا تحتاج انتباهًا',
    'count_in_progress' => '{1} واحد قيد التنفيذ|{2} اثنان قيد التنفيذ|[3,10] :count مشاريع قيد التنفيذ|[11,*] :count مشروعًا قيد التنفيذ',

    // ── الفهرس: عوامل التصفية والبحث وأنماط العرض ───────────────────────
    'filter_all' => 'الكل',
    'filter_attention' => 'يحتاج انتباهًا',
    'filter_in_progress' => 'قيد التنفيذ',
    'filter_completed' => 'مكتمل',
    'filter_environment' => 'البيئة',
    'filter_workspace' => 'مساحة العمل / العميل',
    'filter_all_environments' => 'كل البيئات',
    'filter_all_workspaces' => 'كل مساحات العمل',
    'search_label' => 'البحث في المشاريع',
    'search_placeholder' => 'ابحث بالاسم أو العميل أو المعرّف…',
    'view_cards' => 'بطاقات',
    'view_compact' => 'مضغوط',

    // ── الفهرس: بطاقة المشروع / الصف ────────────────────────────────────
    'card_open' => 'فتح',
    'last_active_never' => 'لا يوجد نشاط بعد',
    'last_active_at' => 'نشِط :when',

    // ── الفهرس: حالات الفراغ وعدم التطابق ───────────────────────────────
    'empty_title' => 'لا توجد مشاريع بعد',
    'empty_body' => 'اربط أول نظام خلفي لديك لتبدأ ترحيله أو إدارته مع TEMM.',
    'empty_cta' => 'اربط مشروعك الأول',
    'empty_viewer_title' => 'لا توجد مشاريع للعرض بعد',
    'empty_viewer_body' => 'ستظهر هنا المشاريع التي يمكنك فتحها بعد أن يربط فريقك أول مشروع.',
    'empty_no_matches_title' => 'لا توجد مشاريع مطابقة',
    'empty_no_matches_body' => 'لا يطابق أي مشروع البحث أو عامل التصفية الحالي.',
    'clear_filters' => 'مسح البحث وعوامل التصفية',

    // ── الفهرس: أعمدة العرض المضغوط ─────────────────────────────────────
    'col_project' => 'المشروع',
    'col_workspace' => 'مساحة العمل',
    'col_environment' => 'البيئة',
    'col_stage' => 'المرحلة',
    'col_status' => 'الحالة',
    'col_progress' => 'التقدّم',
    'col_activity' => 'آخر نشاط',

    // ── الفهرس: ترقيم الصفحات ───────────────────────────────────────────
    'pagination_page' => 'صفحة :current من :last',
    'pagination_previous' => 'السابق',
    'pagination_next' => 'التالي',

    // ── نظرة المشروع العامة: الإجراء الأساسي الوحيد (§D2) ───────────────
    'cta_connect' => 'اربط مصدرًا',
    'cta_analyze' => 'حلّل المصدر',
    'cta_review_plan' => 'راجع الخطة',
    'cta_continue_migration' => 'أكمل الترحيل',
    'cta_check_sync' => 'افحص المزامنة الحية',
    'cta_run_validation' => 'شغّل التحقق',
    'cta_review_cutover' => 'راجع التحويل النهائي',
    'cta_review_attention' => 'راجع ما يحتاج انتباهًا',

    // ── نظرة المشروع العامة: حقول ملخص الحالة (§D3) ─────────────────────
    'fact_progress' => 'تقدّم الترحيل',
    'fact_stage' => 'المرحلة الحالية',
    'fact_health' => 'الصحة',
    'fact_source' => 'اتصال المصدر',
    'fact_sync' => 'المزامنة الحية',
    'fact_backup' => 'آخر نسخة احتياطية',
    'fact_readiness' => 'الجهوزية',
    'source_connected' => 'متصل',
    'source_problem' => 'يحتاج مراجعة',
    'source_not_connected' => 'غير متصل',
    'readiness_not_run' => 'لم يُشغَّل بعد',
    'readiness_blocking' => '{1} فحص واحد مانع|{2} فحصان مانعان|[3,10] :count فحوصات مانعة|[11,*] :count فحصًا مانعًا',
    'readiness_review' => '{1} فحص واحد للمراجعة|{2} فحصان للمراجعة|[3,10] :count فحوصات للمراجعة|[11,*] :count فحصًا للمراجعة',
    'readiness_all_pass' => 'كل الفحوصات ناجحة (:count)',

    // ── نظرة المشروع العامة: الرحلة والانتباه والنشاط (§D4–§D6) ─────────
    'journey_title' => 'رحلة الترحيل',
    'attention_title' => 'يحتاج انتباهًا',
    'attention_open' => 'فتح',
    'activity_title' => 'النشاط الأخير',
    'activity_empty' => 'لا يوجد نشاط مسجل بعد. ستظهر هنا الإجراءات المتخذة في هذا المشروع.',
    'activity_view' => 'عرض نشاط المشروع',
    'activity_backup_state' => 'نسخة احتياطية: :state',
    'activity_schema_change' => 'تغيير في المخطط: :what',

    // ── نظرة المشروع العامة: كشف التفاصيل التقنية (§D7) ─────────────────
    'technical_title' => 'التفاصيل التقنية',
    'tech_project_id' => 'معرّف المشروع',
    'tech_slug' => 'المعرّف (Slug)',
    'tech_workspace' => 'مساحة العمل',
    'tech_environment' => 'البيئة',
    'tech_db_name' => 'اسم قاعدة البيانات',
    'tech_redis_prefix' => 'بادئة Redis',
    'tech_api_domain' => 'نطاق API',
    'tech_domain' => 'النطاق',
    'tech_deployed' => 'آخر نشر',
    'tech_never_deployed' => 'لم يُنشر بعد',
    'tech_database' => 'قاعدة البيانات',
    'tech_reachable' => 'قابلة للوصول',
    'tech_unreachable' => 'خارج النطاق',
    'tech_unavailable' => 'غير متاحة',
    'tech_no_result' => 'لا نتيجة',
    'tech_connections' => '{1} اتصال واحد|{2} اتصالان|[3,10] :count اتصالات|[11,*] :count اتصالًا',
    'tech_no_connection_data' => 'لا توجد بيانات اتصال',
    'tech_api_requests' => 'طلبات API',
    'tech_pulse_note' => '{1} خطأ واحد · لقطة Pulse|{2} خطآن · لقطة Pulse|[3,10] :count أخطاء · لقطة Pulse|[11,*] :count خطأ · لقطة Pulse',
    'tech_queue' => 'قائمة المهام',
    'tech_queue_pending_failed' => ':pending في الانتظار · :failed فاشلة',
    'tech_db_size' => 'حجم قاعدة البيانات',
    'tech_storage' => 'التخزين',
    'tech_app_users' => 'مستخدمو التطبيق',
    'tech_functions' => 'الدوال البرمجية',

    // ── نظرة المشروع العامة: البيئات ────────────────────────────────────
    'env_local' => 'محلية',
    'env_development' => 'تطوير',
    'env_staging' => 'اختبارية',
    'env_production' => 'إنتاج',

    // ── تعطّل الفهرس (§D11) ─────────────────────────────────────────────
    'error_body' => 'تعذّر تحميل قائمة المشاريع. مشاريعك في أمان — أعد المحاولة بعد قليل.',

    // ── نبض المشروع: عناصر الانتباه (§D5/§D9) ───────────────────────────
    'problem_generic' => 'هناك أمر يحتاج إلى مراجعة.',
    'problem_check_blocks' => 'هذا الفحص يمنع التحويل إلى الإنتاج.',
    'problem_run_failed_title' => 'فشلت آخر عملية نقل',
    'problem_run_failed_detail' => 'راجع سجل العملية لمعرفة السبب.',
    'problem_sync_far_title' => 'المزامنة الحية متأخرة كثيرًا',
    'problem_sync_far_detail' => 'آخر تغيير مُطبَّق :when. التحويل إلى الإنتاج غير آمن حتى تلحق المزامنة.',
    'problem_sync_behind_title' => 'المزامنة الحية متأخرة',
    'problem_sync_behind_detail' => 'آخر تغيير مُطبَّق :when.',
    'problem_health_unknown_title' => 'لا توجد نتيجة فحص صحة بعد',
    'problem_health_unknown_detail' => 'شغّل فحص الصحة للحصول على حالة قاطعة.',
    'problem_backup_missing_title' => 'لا نسخة احتياطية مسجلة',
    'problem_backup_missing_detail' => 'التحويل النهائي يحتاج نسخة احتياطية مُتحقَّقًا منها ليكون قابلًا للتراجع.',
    'problem_backup_stale_title' => 'النسخة الاحتياطية الأخيرة قديمة',
    'problem_backup_stale_detail' => 'أُخذت :when.',
    'problem_backup_unverified_title' => 'النسخة الاحتياطية الأخيرة لم تُختبر بالاستعادة',
    'problem_backup_unverified_detail' => 'النسخة التي لم تستعدها ليست مُثبتة بعد.',

    // ── نبض المشروع: تفاصيل مراحل الرحلة (§D4) ──────────────────────────
    'stage_connect_none' => 'لم يُربط أي مصدر بعد.',
    'stage_connect_count' => '{1} تم ربط مصدر واحد.|{2} تم ربط مصدرين.|[3,10] تم ربط :count مصادر.|[11,*] تم ربط :count مصدرًا.',
    'stage_analyze_none' => 'لم يُجرَ التحليل بعد.',
    'stage_analyze_done' => 'آخر تحليل :when.',
    'stage_plan_none' => 'لا خطة بعد.',
    'stage_plan_done' => 'أُعدت الخطة :when.',
    'stage_migrate_none' => 'لا توجد عملية نقل بعد.',
    'stage_migrate_count' => '{1} عملية واحدة، آخرها: :state.|{2} عمليتان، آخرهما: :state.|[3,10] :count عمليات، آخرها: :state.|[11,*] :count عملية، آخرها: :state.',
    'stage_sync_none' => 'لم تبدأ المزامنة الحية بعد.',
    'stage_sync_no_events' => 'لم يُطبَّق أي تغيير بعد.',
    'stage_sync_last' => 'آخر مزامنة :when.',
    'stage_validate_none' => 'لم يُشغَّل التحقق بعد.',
    'stage_validate_failed' => '{1} فحص واحد فاشل.|{2} فحصان فاشلان.|[3,10] :count فحوصات فاشلة.|[11,*] :count فحصًا فاشلًا.',
    'stage_validate_warning' => '{1} فحص واحد يحتاج مراجعة.|{2} فحصان يحتاجان مراجعة.|[3,10] :count فحوصات تحتاج مراجعة.|[11,*] :count فحصًا يحتاج مراجعة.',
    'stage_validate_pass' => 'كل الفحوصات ناجحة (:count).',
    'stage_cutover_none' => 'لا خطة تحويل نهائي بعد.',
    'stage_cutover_blocking' => '{1} عنصر واحد ما زال يمنع التحويل.|{2} عنصران ما زالا يمنعان التحويل.|[3,10] :count عناصر ما زالت تمنع التحويل.|[11,*] :count عنصرًا ما زال يمنع التحويل.',
    'stage_cutover_status' => 'حالة الخطة: :state.',

    // ── نبض المشروع: المزامنة الحية والنسخ الاحتياطي ────────────────────
    'sync_up_to_date' => 'مُحدَّثة',
    'sync_starting' => 'قيد البدء',
    'sync_behind' => 'متأخرة',
    'sync_stopped' => 'متوقفة',
    'sync_not_running' => 'لا تعمل',
    'backup_never' => 'أبدًا',
    'backup_none_detail' => 'لم تُؤخذ أي نسخة احتياطية.',
    'backup_verified_detail' => 'الاستعادة مُتحقَّق منها.',
    'backup_unverified_detail' => 'لم تُختبر بالاستعادة.',

    // ── تسميات حقول مركز الإعدادات ──────────────────────────────────────
    'field_name' => 'الاسم',
    'field_status' => 'الحالة',
    'field_environment' => 'البيئة',
    'field_domain' => 'النطاق',
    'field_api_domain' => 'نطاق API',
    'field_timezone' => 'المنطقة الزمنية',
    'field_locale' => 'اللغة',
    'field_storage_disk' => 'قرص التخزين',
    'field_notes' => 'ملاحظات',

    // ── تنبيهات النظرة العامة ومركز الإعدادات (§D8) ─────────────────────
    'notify_healthy' => 'المشروع سليم',
    'notify_unhealthy' => 'المشروع غير سليم — راجع النظرة العامة',
    'notify_cache_cleared' => 'حُذف :count مفتاحًا تحت :prefix',
    'settings_intro' => 'إعدادات المشروع مجمّعة. المعرّفات التقنية تحت «خيارات متقدمة».',
    'hub_general' => 'عام',
    'hub_general_desc' => 'الاسم والبيئة والنطاقات والإعدادات الافتراضية.',
    'hub_environments' => 'البيئات',
    'hub_environments_desc' => 'أهداف البيئات والترقية بينها.',
    'hub_connections' => 'الاتصالات',
    'hub_connections_desc' => 'تفاصيل اتصال قاعدة البيانات والتجميع والتدوير.',
    'hub_secrets' => 'الأسرار',
    'hub_secrets_desc' => 'اعتمادات يديرها الخزنة. القيم لا تُعرض أبدًا.',
    'hub_team' => 'الفريق والوصول',
    'hub_team_desc' => 'الأعضاء والأدوار والصلاحيات لهذا المشروع.',
    'hub_advanced' => 'خيارات متقدمة',
    'hub_advanced_desc' => 'التفاصيل التقنية المتقدمة والتشخيص.',
    'secret_states_title' => 'حالات الأسرار (القيم لا تُعرض أبدًا)',
    'secret_configured' => 'مُعدّ',
    'secret_not_configured' => 'غير مُعدّ',
    'secret_no_env' => 'لا يوجد ملف .env',
    'secret_rotation_hint' => 'التدوير عبر إجراءات إنشاء المشروع وتدوير الاعتمادات؛ القيم لا تُعرض هنا.',
];
