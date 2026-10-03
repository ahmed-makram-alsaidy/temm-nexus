<?php

// ── كتالوج الموصلات — 0.4.0-rc.5 (المرحلة 41) ────────────────────────

return [

    'title' => 'الموصلات',
    'subtitle' => 'المصادر التي يمكن لـ TEMM Nexus الاتصال بها والترحيل منها وإبقاؤها متزامنة.',

    'capability_live_sync' => 'المزامنة الحية',
    'capability_sync_checkpoints' => 'نقاط تزامن',
    'capability_schema_inspection' => 'فحص المخطط',
    'capability_data_extraction' => 'استخراج البيانات',
    'capability_auth_users' => 'مستخدمو الهوية',
    'capability_storage_files' => 'ملفات التخزين',
    'capability_db_functions' => 'دوال قاعدة البيانات',
    'capability_security_policies' => 'سياسات الأمان',
    'capability_realtime_channels' => 'قنوات الزمن الحقيقي',
    'capability_scheduled_jobs' => 'المهام المجدولة',
    'capability_client_code_scan' => 'فحص كود العميل',
    'capability_read_only' => 'قراءة فقط مفروضة',
    'capability_source_fingerprint' => 'بصمة المصدر',
    'capability_incremental_export' => 'تصدير تزايدي',
    'capability_consistent_snapshot' => 'لقطة متسقة',
    'capability_resume' => 'نقل قابل للاستئناف',
    'capability_change_capture' => 'المزامنة الحية',
    'capability_account_discovery' => 'اكتشاف الحسابات',
    'capability_project_discovery' => 'اكتشاف المشاريع',

    'trust_first_party' => 'من الطرف الأول',
    'trust_partner' => 'شريك',
    'trust_community' => 'المجتمع',

    'category_database' => 'قواعد البيانات',
    'category_baas' => 'خدمات خلفية جاهزة',
    'category_file' => 'ملفات',
    'category_other' => 'أخرى',

    'filter_all' => 'الكل',
    'empty' => 'لا يوجد موصلات مطابقة لهذا الفلتر.',
    'view_docs' => 'الدليل',
    'version_suffix' => 'الإصدار :version',

    'subtitle' => 'كل ما يمكنك الترحيل منه، وما يدعمه كل منها.',
    'search_placeholder' => 'ابحث في الموصلات بالاسم أو القدرة أو المزوّد…',
    'ready_only' => 'الجاهز للاستخدام فقط',
    'clear_filters' => 'مسح عوامل التصفية',
    'showing' => '{0} لا يوجد موصلات|{1} عرض :shown من موصل واحد|[2,*] عرض :shown من :total موصلات.',
    'empty_none' => 'لا توجد موصلات مثبتة',
    'empty_none_body' => 'الموصل هو ما يمكّن المنصة من القراءة من نظام مصدر — قاعدة بيانات أو مشروع Firebase أو مجموعة MongoDB. بدونه لا يوجد ما يمكن الترحيل منه.',
    'empty_filter' => 'لا موصلات تطابق عوامل التصفية هذه',
    'empty_filter_body' => 'توجد :total موصل مثبت، لكن لا أحد يطابق كل عوامل التصفية التي اخترتها. جرّب إزالة أحدها.',
    'trust' => 'الثقة',
    'reads_only' => 'قراءة فقط. لا تكتب المنصة على المصدر أبدًا.',
    'key' => 'المفتاح',
    'import_flow' => 'طريقة الاستيراد',
    'category' => 'الفئة',
    'capabilities' => 'القدرات',
    'not_available' => 'غير متاح على هذه المنصة',
    'connect_in_project' => 'الاتصال داخل مشروع',
    'create_project_to_connect' => 'أنشئ مشروعًا للاتصال',
];
