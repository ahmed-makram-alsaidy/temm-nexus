<?php

// ── التحويل النهائي — 0.6.0 المرحلة E (§E19/§E20) ────────────────────
// نموذج أمان التحويل النهائي بلا تغيير: البوابات والبراهين والاعتمادات
// ونمط «مانع/لماذا» كما قُبل. هذا الملف ينقل نسخة العرض فقط (التسميات
// وجُمل التفاصيل والمدد الزمنية) إلى lang/ حتى لا يبقى شيء إنجليزي
// ثابتًا في الصفحات العربية. بيانات الخطة المخزَّنة تبقى كما سُجلت.

return [

    // الجهوزية الإجمالية (الإجابة الواحدة).
    'overall_blocked_label' => 'مانع',
    'overall_blocked_detail_one' => 'بوابة واحدة تمنع هذا التحويل.',
    'overall_blocked_detail' => ':count بوابات تمنع هذا التحويل.',
    'overall_warning_label' => 'تحذير',
    'overall_warning_detail_one' => 'عنصر واحد يحتاج انتباهًا قبل التبديل.',
    'overall_warning_detail' => ':count عناصر تحتاج انتباهًا قبل التبديل.',
    'overall_ready_label' => 'جاهز',
    'overall_ready_detail' => 'كل البوابات ناجحة. يمكنك المتابعة عند فتح النافذة.',

    // لماذا لا يمكنك المتابعة.
    'issue_blocking' => ':section مانع',
    'issue_unverified' => ':section غير مُتحقق منه',
    'issue_review' => ':section يحتاج مراجعة',

    // حالات البوابات (بوابة بلا برهان تُقرأ «غير مُتحقق منه»، لا أخضر).
    'gate_pass' => 'ناجحة',
    'gate_not_applicable' => 'لا تنطبق',
    'gate_blocked' => 'مانعة',
    'gate_warning' => 'تحذير',
    'gate_unverified' => 'غير مُتحقق منه',

    // المطابقة / التحقّق.
    'validation_error_label' => 'غير مُتحقق منه',
    'validation_error_detail' => 'تعذّرت قراءة التحقّق.',
    'validation_none_label' => 'لم يُشغَّل',
    'validation_none_detail' => 'لم يُسجَّل أي تحقق لهذا المشروع.',
    'validation_failed_label' => 'فاشل',
    'validation_failed_detail' => '{1} فحص واحد من أصل :total فشل.|[2,*] :failed من أصل :total فحص فشلت.',
    'validation_review_label' => 'يحتاج مراجعة',
    'validation_review_detail' => '{1} فحص واحد من أصل :total يحتاج مراجعة.|[2,*] :warned من أصل :total فحص تحتاج مراجعة.',
    'validation_ok_label' => 'مُطابَق',
    'validation_ok_detail' => 'كل الـ :total فحص ناجحة.',

    // النسخة الاحتياطية (نسخة لم تُستعد أبدًا ليست مثبتة بعد).
    'backup_never_label' => 'أبدًا',
    'backup_never_detail' => 'لا نسخة احتياطية مسجلة. لا يمكن أن يكون التحويل قابلًا للرجوع بدونه.',
    'backup_verified_detail' => 'مُتحقق منها ومُجرّبة بالاستعادة.',
    'backup_unproven_detail' => 'غير مثبتة: النسخة التي لم تُستعد ليست بعد خطة تراجع.',

    // جهوزية التراجع.
    'rollback_armed' => 'مجهزة',
    'rollback_no_plan' => 'لا خطة',
    'rollback_not_armed' => 'غير مجهزة',
    'rollback_detail_none_both' => 'لا خطة تحويل نهائي ولا نسخة احتياطية مُتحقق منها. التراجع غير ممكن بعد.',
    'rollback_detail_no_plan' => 'لا توجد خطة تحويل نهائي بعد، لذا لم تُنشأ خطة تراجع.',
    'rollback_detail_unverified' => 'خطة تراجع موجودة، لكن النسخة الاحتياطية خلفها غير مُتحقق منها ومُجرّبة بالاستعادة.',
    'rollback_detail_ready' => 'توجد نسخة احتياطية مُتحقق منها وخطة تراجع مسجلة.',
    'rollback_procedure_none' => 'لا إجراء مسجل.',

    // جهوزية المزامنة النهائية (المدد تُعرض مترجمة — §E20).
    'finalsync_unverified_label' => 'غير مُتحقق',
    'finalsync_unverified_detail' => 'لم تبلّغ المزامنة الحية، فلا يمكن الحكم على الفارق النهائي.',
    'finalsync_behind_label' => 'متأخرة',
    'finalsync_behind_detail' => 'آخر تغيير طُبِّق :when. أي فارق نهائي الآن سيكون كبيرًا.',
    'finalsync_catching_label' => 'تستدرك',
    'finalsync_catching_detail' => 'آخر تغيير طُبِّق :when.',
    'finalsync_uptodate_label' => 'محدّثة',
    'finalsync_uptodate_detail' => 'آخر تغيير طُبِّق :when.',

    // تسميات بوابات الاعتماد (معرّفات البوابات مخزنة كما هي).
    'gate_label_backup_restore_drill' => 'تجربة استعادة النسخة الاحتياطية',
    'gate_label_final_delta' => 'الفارق النهائي',
    'gate_label_endpoint_switch' => 'تبديل النقطة النهائية',
    'gate_label_source_freeze' => 'تجميد المصدر',

    // تسميات خطوات خطة التحويل المرتبة.
    'step_verify_backup' => 'التحقق من النسخة الاحتياطية',
    'step_verify_target' => 'التحقق من الوجهة',
    'step_verify_snapshot' => 'التحقق من اللقطة',
    'step_verify_cdc_lag' => 'التحقق من تأخر المزامنة الحية',
    'step_source_freeze' => 'تجميد المصدر',
    'step_final_delta' => 'تطبيق الفارق النهائي',
    'step_reconcile' => 'مطابقة الوجهة',
    'step_endpoint_switch' => 'تبديل النقطة النهائية',
    'step_smoke_test' => 'تشغيل فحوص سريعة',
    'step_observe' => 'الملاحظة',
    'step_complete_or_rollback' => 'الإكمال أو التراجع',

    // غلاف الصفحة.
    'readiness_overall' => 'الجهوزية الإجمالية',
    'readiness_blocking' => 'مانع',
    'readiness_blocking_detail' => 'بوابات تمنع التبديل',
    'readiness_to_verify' => 'للتحقق',
    'readiness_to_verify_detail' => 'تحذيرات وبوابات غير مُتحقق منها',
    'readiness_approvals_outstanding' => 'اعتمادات معلّقة',
    'readiness_approvals_of' => 'من أصل :total مطلوبة',
    'readiness_plan' => 'الخطة',
    'readiness_plan_recorded' => 'مسجلة',
    'readiness_plan_missing' => 'غير منشأة',
    'readiness_plan_missing_detail' => 'شغّل الفحص المسبق لتسجيل واحدة',
    'readiness_begin_window' => 'بدء نافذة التحويل',
    'readiness_begin_note' => 'لن تُغيّر المنصة DNS أو النقاط النهائية. الخطة المرتبة أدناه لمشغّل ينفذها ويسجلها.',
    'readiness_locked' => 'التحويل النهائي غير متاح بعد',
    'readiness_locked_reason' => 'عالج العناصر أدناه أولًا.',
    'readiness_why_blocked' => 'لماذا لا يمكنك المتابعة',
    'readiness_gates' => 'بوابات الجهوزية',
    'readiness_gates_desc' => 'البوابة بلا برهان تُقرأ «غير مُتحقق منها» لا خضراء. المنصة لا تخمّن الجهوزية أبدًا.',
    'readiness_detail' => 'تفصيل الجهوزية',
    'readiness_sync' => 'المزامنة الحية',
    'readiness_reconciliation' => 'المطابقة',
    'readiness_backup' => 'النسخ الاحتياطي',
    'readiness_rollback' => 'التراجع',
    'readiness_final_sync' => 'المزامنة النهائية',
    'readiness_approvals' => 'اعتمادات بشرية',
    'readiness_approvals_desc' => 'كل بوابة تمس الإنتاج تحتاج قرارًا صريحًا. البوابة بلا سجل منتظرة، لا ممنوحة.',
    'readiness_approved_by' => 'اعتمدها :name',
    'readiness_approved' => 'معتمدة',
    'readiness_rejected_by' => 'رفضها :name',
    'readiness_rejected' => 'مرفوضة',
    'readiness_awaiting' => 'بانتظار قرار',
    'readiness_awaiting_state' => 'منتظرة',
    'readiness_cannot_approve' => 'يمكنك مراجعة هذه الشاشة لكن دورك لا يتضمن',
    'readiness_cannot_approve_suffix' => 'لذا لا يمكنك تسجيل القرارات هنا.',
    'readiness_plan_title' => 'خطة التحويل المرتبة',
    'readiness_plan_desc' => 'الخطوات المعلَّمة كتتطلب اعتماد مُحكمة. المنصة تسجّل كل خطوة؛ ولا تنفّذ تغييرات DNS أو النقاط النهائية.',
    'readiness_approval_tag' => 'اعتماد',
    'readiness_advanced' => 'تفاصيل متقدمة',
    'readiness_rollback_procedure' => 'إجراء التراجع',
];
