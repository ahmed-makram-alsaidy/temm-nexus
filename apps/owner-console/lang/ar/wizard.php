<?php

// ── معالج مشروع جديد — 0.4.0-rc.5 (المرحلة 41، الجزء B) ──────────────

return [

    'title' => 'مشروع جديد',
    'subtitle' => 'اربط نظامًا خلفيًا في ست خطوات موجّهة. يمكنك التوقف في أي لحظة وإكمال العمل لاحقًا.',

    'step_project' => 'المشروع',
    'step_source' => 'المصدر',
    'step_connection' => 'الاتصال',
    'step_destination' => 'الوجهة',
    'step_analyze' => 'التحليل',
    'step_review' => 'المراجعة',

    'step_of' => 'الخطوة :current من :total',

    // الخطوة 1 — المشروع.
    'project_name' => 'اسم المشروع',
    'project_name_helper' => 'اسم مفهوم للبشر، مثل «موقع شركة أكمة».',
    'workspace_client' => 'مساحة العمل / العميل',
    'workspace_client_helper' => 'أي عميل أو فريق يملك هذا المشروع.',
    'environment' => 'البيئة',
    'env_helper_production' => 'خوادم المصدر مباشرة (Live). النقل للقراءة فقط وتُطبَّق فحوصات إضافية.',

    // الخطوة 2 — المصدر.
    'source_pick' => 'أين توجد البيانات اليوم؟',
    'source_pick_helper' => 'اختر النظام الذي تُرحّل منه. يمكنك إضافة مصادر أخرى لاحقًا.',
    'source_migration' => 'الترحيل',
    'source_live_sync' => 'المزامنة الحية',
    'source_capability_migration' => 'ينقل البيانات خارج المصدر',
    'source_capability_live_sync' => 'يُبقي النظام الجديد متزامنًا حتى التحويل النهائي',
    'source_capability_read_only' => 'وصول للقراءة فقط مفروض',

    // الخطوة 3 — الاتصال.
    'connection_title' => 'الاتصال بـ :name',
    'connection_helper' => 'الحقول اللازمة للمزوّد فقط. كلمات المرور تذهب مباشرة إلى الخزنة المشفَّرة.',
    'test_first' => 'اختبر الاتصال قبل المتابعة.',
    'test_running' => 'جارٍ اختبار الاتصال…',
    'test_passed' => 'تم الاتصال بنجاح',
    'test_failed' => 'فشل الاتصال',
    'test_unknown' => 'لم نتمكن من التحقق من الاتصال. يمكنك المتابعة، لكن خطوة التحليل ستفشل إذا كانت البيانات خاطئة.',
    'ssl_toggle' => 'اشتراط SSL',
    'secrets_note' => 'تُخزَّن كلمات المرور في خزنة المشروع مشفَّرةً في التخزين ولا تُعرض مرة أخرى.',
    'no_fields_needed' => 'لا يحتاج هذا المزوّد أي حقول اتصال.',

    // الخطوة 4 — الوجهة.
    'destination_title' => 'إلى أين تذهب البيانات؟',
    'destination_temm' => 'بنية تحتية يديرها TEMM',
    'destination_temm_helper' => 'موصى به. تُهيئ TEMM Nexus الوجهة وتديرها — لا شيء تحتاج إلى إعداده.',
    'destination_external' => 'خادم PostgreSQL خارجي',
    'destination_external_helper' => 'أنت توفّر قاعدة البيانات الوجهة. يُستخدم للترحيل إلى بنية تحتية تديرها بنفسك.',
    'target_host' => 'مستضيف الوجهة',
    'target_port' => 'منفذ الوجهة',
    'target_database' => 'قاعدة بيانات الوجهة',
    'target_username' => 'اسم مستخدم الوجهة',
    'target_password' => 'كلمة مرور الوجهة',
    'target_password_helper' => 'تُخزَّن كسر في الخزنة ويُشار إليها بالاسم — لا تُعرض مرة أخرى.',
    'target_disposable' => 'هذه الوجهة قابلة للإتلاف (يُمكن إعادة تعيينها بأمان)',
    'destination_note' => 'لا يُكتب شيء على الوجهة أثناء خطوة التحليل. أول كتابة حقيقية تحدث عند بدء الترحيل.',

    // الخطوة 5 — التحليل.
    'analyze_title' => 'جارٍ تحليل :name',
    'analyze_helper' => 'تقرأ TEMM Nexus بيانات التعريف فقط — لا تخرج أي بيانات من المصدر.',
    'analyze_button' => 'تحليل المصدر',
    'analyze_running' => 'جارٍ قراءة المصدر…',
    'analyze_summary' => 'ما وجدناه',
    'analyze_counts_tables' => 'الجداول',
    'analyze_counts_views' => 'العروض',
    'analyze_counts_auth' => 'المستخدمون',
    'analyze_counts_storage' => 'التخزين',
    'analyze_counts_functions' => 'الدوال',
    'analyze_counts_triggers' => 'المشغلات',
    'analyze_counts_policies' => 'سياسات الأمان',
    'analyze_counts_realtime' => 'قنوات الزمن الحقيقي',
    'analyze_issues' => 'مشكلات محتملة',
    'analyze_issues_none' => 'لم تُكتشف مشكلات مانعة.',
    'analyze_done' => 'اكتمل التحليل.',
    'analyze_failed' => 'لم يكتمل التحليل: :reason',

    // الخطوة 6 — المراجعة.
    'review_title' => 'مراجعة الخطة',
    'review_helper' => 'ملخص جهوزية بلغة مبسطة. تبقى الخطة التقنية الكاملة متاحة في مركز الترحيل.',
    'review_database' => 'قاعدة البيانات',
    'review_users' => 'المستخدمون',
    'review_storage' => 'التخزين',
    'review_live_sync' => 'المزامنة الحية',
    'review_client_code' => 'كود العميل',
    'review_client_changes' => '{0} لا توجد تغييرات|{1} اكتُشف تغيير واحد|{2} اكتُشف تغييران|[3,10] اكتُشفت :count تغييرات|[11,*] اكتُشف :count تغييرًا',
    'review_plan_items' => '{0} الخطة لا تحتوي خطوات نقل|{1} تحتوي الخطة على خطوة نقل واحدة|{2} تحتوي الخطة على خطوتي نقل|[3,10] تحتوي الخطة على :count خطوات نقل|[11,*] تحتوي الخطة على :count خطوة نقل',
    'start_migration' => 'بدء الترحيل',
    'start_migration_helper' => 'يشغّل تجربة جافة أولًا — لا تُكتب أي بيانات حتى تختار النقل الحقيقي.',
    'open_migration_center' => 'الخطة التقنية الكاملة',
    'migration_started' => 'بدأت عملية الترحيل: :status',
    'project_created' => 'تم إنشاء مشروع «:name».',
    'review_ready' => 'جاهز',
    'review_needs_review' => 'يحتاج مراجعة',
    'review_supported' => 'مدعوم',
    'review_not_supported' => 'غير مدعوم',
    'review_scan_hint' => 'شغّل فحص العميل من صفحة المشروع لاكتشاف تغييرات الكود.',

    // غلاف المعالج.
    'continue_disabled_hint' => 'أكمل هذه الخطوة للمتابعة.',
    'created_note' => 'المشروع والاتصال محفوظان. يمكنك مغادرة المعالج بأمان وإكمال العمل لاحقًا.',
    'back_warning' => 'الرجوع آمن: القيم التي أدخلتها محفوظة.',
    'not_sure_ask_nexus_ai' => 'لست متأكدًا ماذا تختار؟ اسأل Nexus AI',
];
