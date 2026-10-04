<?php

// ── التنقّل في المنتج — 0.4.0-rc.5 (المرحلة 41) ──────────────────────

return [

    // الإدخالات الرئيسية (بترتيب ProductNavigation::PLATFORM_ENTRIES).
    'home' => 'الرئيسية',
    'projects' => 'المشاريع',
    'workspaces' => 'العملاء ومساحات العمل',
    'connectors' => 'الموصلات',
    'nexus_ai' => 'Nexus AI',
    'operations' => 'العمليات',
    'infrastructure' => 'البنية التحتية',
    'security' => 'الأمان',
    'settings' => 'الإعدادات',

    // مجموعة العمليات.
    'queues' => 'طوابير المهام',
    'logs' => 'السجلات',
    'monitoring' => 'المراقبة',
    'scheduler' => 'المجدول',
    'webhooks' => 'Webhooks',
    'realtime' => 'الزمن الحقيقي',

    // مجموعة البنية التحتية.
    'nodes' => 'العُقد',
    'services' => 'الخدمات',
    'health' => 'الحالة الصحية',
    'topology' => 'المخطط الشبكي',

    // مجموعة الأمان.
    'members' => 'الأعضاء',
    'audit_log' => 'سجل التدقيق',

    // مجموعة الإعدادات.
    'get_started' => 'البداية',
    'search' => 'البحث',
    'nexus_ai_settings' => 'إعدادات Nexus AI',
    'language_region' => 'اللغة',

    // مجموعات الشريط الجانبي للمشروع.
    'group_database' => 'قاعدة البيانات',
    'group_authentication' => 'المصادقة',
    'group_build' => 'البناء',
    'group_operate' => 'التشغيل',
    'group_project' => 'المشروع',

    // عناصر الشريط الجانبي للمشروع.
    'tables' => 'الجداول',
    'erd' => 'ERD',
    'sql_editor' => 'محرر SQL',
    'schema' => 'المخطط',
    'functions' => 'الدوال',
    'inspector' => 'الفاحص',
    'migrations' => 'الترحيلات',
    'schema_diff' => 'مقارنة المخططات',
    'db_health' => 'الحالة الصحية',
    'connections' => 'الاتصالات',
    'users' => 'المستخدمون',
    'roles' => 'الأدوار',
    'permissions' => 'الصلاحيات',
    'sessions' => 'الجلسات',
    'providers' => 'مزوّدو الهوية',
    'secrets' => 'الأسرار',
    'storage' => 'التخزين',
    'api' => 'API',
    'connect' => 'الاتصال',
    'api_keys' => 'مفاتيح API',
    'backups' => 'النسخ الاحتياطية',
    'resources' => 'الموارد',
    'readiness' => 'الجهوزية',
    'environments' => 'البيئات',
    'migration_center' => 'مركز الترحيل',
    'cutover' => 'التحويل النهائي',
    'client_repository' => 'مستودع العميل',
    'ai_copilot' => 'مساعد AI',
    'overview' => 'نظرة عامة',
    'team' => 'الفريق',

    // ── هيكل المنتج المبسّط — المرحلة ب من 0.6.0 ──────────────────────────
    // وجهات المنصّة (الرئيسية · المشاريع · العملاء · النشاط · الذكاء
    // الاصطناعي · الإعدادات) ونموذج سياق المشروع (تبويبات، لا روابط جانبية).
    'clients' => 'العملاء',
    'activity' => 'النشاط',
    'ai_group' => 'الذكاء الاصطناعي',
    'developer_agent' => 'الوكيل المطوّر',

    // التبويبات الرئيسية للمشروع (بترتيب ProjectTabs::TABS).
    'tab_migration' => 'الترحيل',
    'tab_data' => 'البيانات',
    'tab_access' => 'الوصول',

    // عناصر سياق المشروع.
    'project_context' => 'أقسام المشروع',
    'active_environment' => 'البيئة النشطة — افتحها للتبديل',
    'manage_environments' => 'إدارة البيئات',
    'inactive' => 'غير نشطة',
];
