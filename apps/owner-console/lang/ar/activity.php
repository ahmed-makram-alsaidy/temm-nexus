<?php

/* ── النشاط بلغة بشرية — المرحلة أ من 0.6.0 (تدقيق §A6) ──────────────
   تحويل أفعال التدقيق الخام إلى جمل قصيرة مفهومة على أسطح المنتج
   (نشاط الصفحة الرئيسية، نظرة عامة على المشروع، صفحة النشاط).
   يبقى الفعل الخام محفوظًا دائمًا في تفاصيل التدقيق والبيانات التقنية. */

return [
    // المنصّة والإعداد
    'platform_setup_completed' => 'اكتمل إعداد المنصّة',

    // المشاريع والبيئات والصحة
    'project_health_checked' => 'فحص الحالة الصحية',
    'environment_created' => 'أنشأ بيئة',
    'environment_updated' => 'حدّث بيئة',
    'environment_switched' => 'بدّل البيئة',
    'promotion_attempted' => 'حاول ترقية بيئة',

    // الترحيل
    'wizard_source_connected' => 'ربط مصدر بيانات',
    'migration_source_created' => 'أنشأ مصدر ترحيل',
    'source_credential_changed' => 'حدّث بيانات اعتماد المصدر',
    'migration_analysis_run' => 'حلّل المصدر',
    'migration_plan_created' => 'أنشأ خطة ترحيل',
    'migration_run_started' => 'بدأ تشغيل ترحيل',
    'migration_run_cancelled' => 'ألغى تشغيل ترحيل',
    'migration_run_failed' => 'فشل تشغيل ترحيل',
    'migration_validated' => 'تحقّق من صحة البيانات المُرحّلة',
    'wizard_migration_started' => 'بدأ ترحيلًا',
    'cutover_event' => 'سجّل حدث تحويل نهائي',
    'clean_rehearsal_run' => 'شغّل تجربة تدريب نظيفة',
    'schema_snapshot_created' => 'أنشأ لقطة لمخطط قاعدة البيانات',
    'schema_diff_run' => 'قارن لقطات المخطط',
    'readiness_acknowledged' => 'أقرّ بعنصر جهوزية',
    'readiness_snapshot_recorded' => 'سجّل لقطة جهوزية',

    // قاعدة البيانات والسجلات
    'records_exported' => 'صدّر سجلات جدول',
    'write_mode_enabled' => 'فعّل وضع الكتابة في محرّر SQL',

    // الأسرار والمفاتيح
    'secret_created' => 'أنشأ سرًّا',
    'secret_rotated' => 'بدّل قيمة سر',
    'secret_deleted' => 'حذف سرًّا',
    'secret_revealed' => 'اعرض قيمة سر',
    'apikey_created' => 'أنشأ مفتاح API',
    'apikey_rotated' => 'بدّل مفتاح API',
    'apikey_revoked' => 'أبطل مفتاح API',

    // موصلات العملاء
    'client_connection_tested' => 'اختبر اتصال عميل',
    'client_scan_run' => 'فحص كود العميل',
    'client_conversion_generated' => 'أنشأ تحويلًا لعميل',
    'client_conversion_test_refused' => 'رفض اختبار تحويل غير آمن لعميل',
    'connector_instance_disabled' => 'عطّل موصلًا',
    'connector_instance_removed' => 'أزال موصلًا',
    'connector_package_rejected' => 'رفض حزمة موصل',
    'supabase_account_connected' => 'ربط حساب Supabase',
    'supabase_connection_tested' => 'اختبر اتصال Supabase',
    'supabase_project_selected' => 'اختار مشروع Supabase',
    'supabase_account_deleted' => 'أزال حساب Supabase',
    'repository_linked' => 'ربط مستودع كود العميل',

    // الدوال والمهام وWebhooks والتخزين
    'function_deployed' => 'نشر دالة',
    'function_rolled_back' => 'تراجع عن إصدار دالة',
    'function_invoked' => 'شغّل دالة',
    'task_run' => 'شغّل مهمة',
    'test_run' => 'شغّل اختبارًا',
    'webhook_delivered' => 'سلّم إشعار Webhook',
    'storage_file_downloaded' => 'نزّل ملفًا من التخزين',

    // النسخ الاحتياطي والاستعادة
    'backup_destination_created' => 'أنشأ وجهة نسخ احتياطي',
    'backup_policy_created' => 'أنشأ سياسة نسخ احتياطي',
    'restore_drill_requested' => 'طلب تجربة استعادة',
    'restore_drill_passed' => 'نجحت تجربة الاستعادة',
    'restore_drill_failed' => 'فشلت تجربة الاستعادة',

    // البنية التحتية
    'node_token_rotated' => 'بدّل رمز عقدة',
    'resource_threshold_updated' => 'حدّث حدود الموارد',
    'cost_entry_updated' => 'حدّث تقدير تكلفة',

    // الذكاء الاصطناعي والوكلاء
    'ai_provider_saved' => 'حفظ إعدادات مزوّد الذكاء الاصطناعي',
    'copilot_run' => 'شغّل مساعد الترحيل',
    'patch_generated' => 'أنشأ ترقيعًا برمجيًا',
    'patch_approved' => 'وافق على ترقيع برمجي',
    'patch_rejected' => 'رفض ترقيعًا برمجيًا',
    'patch_applied' => 'طبّق ترقيعًا برمجيًا',
    'agent_runtime_created' => 'أنشأ بيئة تشغيل للوكيل',
    'agent_runtime_updated' => 'حدّث بيئة تشغيل الوكيل',
    'agent_runtime_tested' => 'اختبر بيئة تشغيل الوكيل',
    'agent_task_created' => 'أنشأ مهمة وكيل',
    'agent_task_cancelled' => 'ألغى مهمة وكيل',
    'agent_task_failed' => 'فشلت مهمة وكيل',
    'agent_changeset_generated' => 'أنشأ الوكيل تغييرات برمجية',
    'agent_changeset_applied' => 'طبّق تغييرات الوكيل',
    'agent_file_applied' => 'طبّق تغيير ملف من الوكيل',
    'agent_approval_granted' => 'وافق على تغييرات الوكيل',
    'agent_approval_rejected' => 'رفض تغييرات الوكيل',
    'agent_verification_recorded' => 'سجّل نتيجة تحقّق الوكيل',
    'agent_apply_failed' => 'فشل تطبيق تغييرات الوكيل',

    // الفريق والمستخدمون
    'team_role_assigned' => 'أسند دورًا لأحد الأعضاء',
    'user_password_reset_sent' => 'أرسل رابط إعادة تعيين كلمة مرور',
    'onboarding_started' => 'بدأ الإعداد التمهيدي',
    'onboarding_completed' => 'أكمل الإعداد التمهيدي',
];
