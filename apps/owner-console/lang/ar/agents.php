<?php

/*
|--------------------------------------------------------------------------
| وكلاء المطوّر / منصة تشغيل الوكلاء (المرحلة 43)
|--------------------------------------------------------------------------
|
| النصوص الظاهرة للمستخدم في منصة تشغيل وكلاء البرمجة. المعرّفات التقنية
| (معرّفات النماذج، مراجع الإصدارات، المسارات، الأوامر، عناوين الخدمة)
| تُعرض دائمًا باتجاه LTR ولا تُترجم أبدًا.
*/

return [
    // التنقّل والعناوين
    'nav_settings' => 'وكلاء المطوّرة',
    'nav_workbench' => 'وكيل المطوّرة',
    'settings_title' => 'وكلاء المطوّرة',
    'settings_subtitle' => 'إعداد وكلاء البرمجة. تعمل المهام في مساحات عمل معزولة ولا تلمس الشيفرة المصدرية دون موافقتك.',
    'workbench_title' => 'وكيل المطوّرة',
    'workbench_subtitle' => 'أوكّل مهمة برمجية، وتابع عملها في مساحة عمل معزولة، وراجع الفروقات، ثم وافق وطبّق.',

    // قائمة الوكلاء المُهيّأة
    'runtimes_title' => 'الوكلاء المُهيّأة',
    'runtimes_empty' => 'لم يُهيَّأ أي وكيل برمجة بعد.',
    'status_untested' => 'لم يُختبر',
    'unknown' => 'غير معروف',
    'model_runtime_default_none' => 'افتراضي الوكيل (غير محدد)',
    'test_never' => 'لم يُختبر الاتصال قط',
    'mode_managed' => 'مُدار',
    'mode_external' => 'خارجي',
    'managed_secret' => 'مصادقة الخدمة',
    'managed_secret_set' => 'مُهيّأة عبر سر المنظومة',
    'managed_secret_missing' => 'المتغير OPENCODE_SERVER_PASSWORD غير مضبوط',

    // استمارة الوكيل
    'new_runtime_title' => 'إضافة وكيل',
    'edit_runtime_title' => 'تعديل الوكيل',
    'field_driver' => 'الوكيل',
    'field_display_name' => 'الاسم الظاهر',
    'field_mode' => 'نمط التشغيل',
    'mode_helper' => '«مُدار» يعمل داخل حزمة دوكر لهذه المنصة؛ و«خارجي» يشير إلى خدمة تديرها بنفسك.',
    'field_endpoint' => 'عنوان الخدمة',
    'endpoint_helper' => 'يجب أن تستخدم العناوين الخارجية HTTPS. يستخدم الوكلاء المُدارة العنوان الداخلي للخدمة.',
    'endpoint_required' => 'عنوان الخدمة مطلوب للوكلاء الخارجية.',
    'field_auth_secret' => 'كلمة مرور المصادقة',
    'auth_secret_helper' => 'للكتابة فقط. اتركها فارغة للإبقاء على القيمة المخزنة. تُخزَّن مشفّرة ولا تُعرض مجددًا.',
    'field_default_model' => 'النموذج الافتراضي',
    'default_model_helper' => 'المعرّف القياسي provider/model كما يبلغ عنه الوكيل تمامًا (مثل google/gemini-2.5-pro).',
    'field_timeout' => 'الميزانية الزمنية للمهمة (ثانية)',
    'field_retention' => 'مدة الاحتفاظ بمساحة العمل (أيام)',
    'retention_helper' => 'تُحذف مساحات العمل المعزولة بعد هذا العدد من الأيام. الصفر يعني الاحتفاظ بها حتى المراجعة والتنظيف اليدوي.',
    'field_version' => 'إصدار الوكيل',
    'field_last_test' => 'آخر اختبار اتصال',
    'field_concurrency' => 'الحد الأقصى للمهام المتوازية',

    // إجراءات الوكيل
    'edit' => 'تعديل',
    'test_connection' => 'اختبار الاتصال',
    'test_passed' => 'نجح الاتصال',
    'test_failed' => 'فشل الاتصال',
    'discover_models' => 'استكشاف النماذج',
    'models_failed' => 'فشل استكشاف النماذج',
    'models_discovered' => 'عُثر على :count نموذجًا (يُعرض أول 20).',
    'models_truncated' => 'قائمة مقتطعة للعرض.',
    'saved' => 'حُفظ الوكيل.',

    // سياسة التنفيذ
    'policy_title' => 'سياسة التنفيذ (الإصدار 1)',
    'policy_help' => 'ما يُسمح للوكيل البرمجي بفعله. تُفرض هذه الحدود من المنصة نفسها، لا من الواجهة.',
    'policy_column' => 'القدرة',
    'verdict_column' => 'الحد',
    'policy_read' => 'قراءة الملفات',
    'policy_write' => 'كتابة الملفات',
    'policy_execute' => 'تنفيذ الأوامر',
    'policy_network' => 'الوصول إلى الشبكة',
    'policy_apply' => 'التطبيق على المصدر',
    'policy_deploy' => 'النشر',
    'verdict_runtime' => 'داخل مساحة العمل المخصصة فقط',
    'verdict_approval' => 'تتطلب موافقة بشرية صريحة',
    'verdict_denied' => 'غير متاح في هذا الإصدار',

    // منضدة العمل — مهمة جديدة
    'new_task_title' => 'بدء مهمة',
    'new_task_help' => 'يعمل الوكيل في نسخة معزولة جديدة من مشروعك. لا يصل أي شيء إلى المصدر الحقيقي حتى توافق على الفروقات.',
    'new_task_unavailable' => 'تحتاج إلى وكيل مُفعّل ومشروع يمكنك تشغيل الوكلاء عليه.',
    'field_project' => 'المشروع',
    'field_runtime' => 'الوكيل',
    'field_model' => 'النموذج',
    'model_runtime_default' => 'افتراضي الوكيل',
    'model_helper' => 'تأتي معرّفات النماذج من الوكيل نفسه. اتركه فارغًا لاستخدام الافتراضي.',
    'field_title' => 'العنوان (اختياري)',
    'field_prompt' => 'المهمة',
    'start_task' => 'بدء المهمة',
    'task_created' => 'أُنشئت المهمة :code.',
    'task_create_failed' => 'تعذّر إنشاء المهمة.',

    // منضدة العمل — قائمة المهام والتفاصيل
    'tasks_title' => 'المهام الأخيرة',
    'tasks_empty' => 'لا توجد مهام بعد.',
    'field_base_revision' => 'مرجع المصدر',
    'field_error' => 'خطأ',
    'field_usage' => 'الاستهلاك',
    'approve' => 'الموافقة على حزمة التغييرات',
    'reject' => 'الرفض',
    'apply' => 'التطبيق على المصدر',
    'cancel_task' => 'إلغاء المهمة',
    'approval_granted' => 'وُافقت حزمة التغييرات.',
    'approval_rejected' => 'رُفضت حزمة التغييرات.',
    'approval_failed' => 'فشل إجراء الموافقة.',
    'applied' => 'طُبّقت حزمة التغييرات على المصدر.',
    'apply_failed' => 'فشل التطبيق.',
    'cancelled' => 'أُلغيت المهمة.',
    'cancel_failed' => 'فشل الإلغاء.',

    // حالات المهمة
    'status_queued' => 'في الانتظار',
    'status_starting' => 'بدء التشغيل',
    'status_running' => 'قيد التنفيذ',
    'status_awaiting_approval' => 'بانتظار الموافقة',
    'status_applying' => 'قيد التطبيق',
    'status_verifying' => 'قيد التحقق',
    'status_completed' => 'مكتملة',
    'status_failed' => 'فاشلة',
    'status_cancelled' => 'ملغاة',
    'status_stale' => 'متجاوزة',

    // سجل النشاط
    'activity_title' => 'النشاط',
    'activity_empty' => 'لا يوجد نشاط بعد.',
    'event_status' => 'الحالة',
    'event_thinking' => 'استدلال',
    'event_reading' => 'قراءة',
    'event_editing' => 'تحرير',
    'event_file_changed' => 'تغيير ملف',
    'event_command' => 'أمر',
    'event_testing' => 'اختبار',
    'event_plan' => 'خطة',
    'event_permission' => 'إذن',
    'event_message' => 'رسالة',
    'event_error' => 'خطأ',
    'event_completed' => 'اكتمل',

    // الأوامر
    'commands_title' => 'الأوامر المنفّذة',
    'commands_empty' => 'لم تُنفَّذ أي أوامر.',
    'field_command' => 'الأمر',
    'field_exit_code' => 'رمز الخروج',
    'field_duration' => 'المدة',
    'field_output' => 'المخرجات',
    'field_status' => 'الحالة',
    'command_status_running' => 'قيد التنفيذ',
    'command_status_completed' => 'مكتمل',
    'command_status_failed' => 'فاشل',
    'command_status_timeout' => 'انتهت مهلته',
    'command_status_cancelled' => 'ملغى',
    'commands_privacy_note' => 'لا يُخزَّن محتوى مخرجات الأوامر إطلاقًا — الحجم وحالة الاقتطاع فقط.',
    'truncated' => 'مقتطع',

    // الفروقات
    'diff_title' => 'حزمة التغييرات',
    'diff_files' => 'الملفات (مضافة / معدلة / محذوفة)',
    'diff_fingerprint' => 'بصمة حزمة التغييرات',
    'diff_truncated' => 'تتجاوز هذه الحزمة حد الحجم ولا يمكن تطبيقها تلقائيًا.',

    // التحقق
    'verification_title' => 'التحقق',
    'verification_passed' => 'ناجح',
    'verification_failed' => 'فاشل',
    'verification_error' => 'خطأ',
    'verification_skipped' => 'تَمَّ تجاوزه (لا توجد أوامر مُصَدَّقة)',
];
