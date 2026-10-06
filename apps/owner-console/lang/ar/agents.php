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
    // H7: خيارات الاتصال وحدود التشغيل على مستوى المشغّل تعيش خلف «خيارات متقدمة»
    // (يرتقي عنوان الخدمة وكلمة المرور إلى الشبكة الأساسية للوكيل الخارجي حيث
    // هما حقل الإعداد الأساسيّان).
    'advanced_section' => 'خيارات متقدمة',
    'advanced_section_hint' => 'خيارات الاتصال وحدود التشغيل — معظم الإعدادات لا تحتاجها أبدًا.',
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
    'verification_title' => 'الاختبارات',
    'verification_passed' => 'نجحت الاختبارات',
    'verification_failed' => 'فاشل',
    'verification_failed_title' => 'فشل :count فحص/فحوصات',
    'verification_error' => 'خطأ',
    'verification_skipped' => 'تَمَّ تجاوزه (لا فحوصات مُعدَّة)',
    'verification_passed_body' => 'نجحت جميع فحوصات التحقق بعد تطبيق التغييرات.',
    'verification_failed_body' => 'طُبِّقت التغييرات، لكن التحقق يحتاج انتباهك. لم يُسترجع شيء تلقائيًا.',
    'verification_output_note' => 'لا يُخزَّن محتوى مخرجات الأوامر إطلاقًا — نتيجة كل فحص وحجمه ومدته فقط.',

    // ── المرحلة G — منضدة العمل بحالة الوظيفة أولًا ─────────────────

    // حالات الوظيفة البشرية (G5) — لا يُعاد كتابة القيم المخزنة أبدًا.
    'human_status_queued' => 'بانتظار البدء',
    'human_status_starting' => 'جارٍ البدء',
    'human_status_planning' => 'التخطيط',
    'human_status_editing' => 'تعديل الملفات',
    'human_status_testing' => 'تشغيل الاختبارات',
    'human_status_working' => 'يعمل الآن',
    'human_status_awaiting_approval' => 'جاهز للمراجعة',
    'human_status_applying' => 'تطبيق التغييرات الموافق عليها',
    'human_status_verifying' => 'جارٍ التحقق',
    'human_status_completed' => 'مكتملة',
    'human_status_failed' => 'يحتاج انتباهك',
    'human_status_cancelled' => 'ملغاة',
    'human_status_stale' => 'يحتاج مراجعة جديدة',

    // ماذا يحدث بعد ذلك، لكل حالة (G1).
    'next_queued' => 'بانتظار خالية في وقت التشغيل.',
    'next_starting' => 'تحضير مساحة العمل المعزولة وبدء الوكيل.',
    'next_running' => 'يعمل الوكيل في النسخة المعزولة من مشروعك.',
    'next_awaiting_approval' => 'راجع التغييرات أدناه وقرر — لا يُطبَّق شيء حتى توافق.',
    'next_applying' => 'تطبيق التغييرات التي وافقت عليها في المستودع.',
    'next_verifying' => 'تشغيل فحوصات التحقق على التغييرات المطبقة.',
    'next_completed' => 'انتهت. التغييرات في مستودعك الآن.',
    'next_failed' => 'انظر ماذا فشل أدناه — لم يُطبَّق أي ضرر.',
    'next_cancelled' => 'أُلغيت. لم يُطبَّق أي شيء.',
    'next_stale' => 'تغيّرت التغييرات المراجَعة بعد المراجعة — راجعها من جديد قبل الموافقة.',

    // الجدول الزمني للنشاط (G6) — جمل بشرية؛ الأحداث الخام في التفاصيل التقنية.
    'timeline_status' => 'حُدِّثت الوظيفة',
    'timeline_approved' => 'وافق :name',
    'timeline_declined' => 'رُفضت التغييرات — لم يُطبَّق أي شيء',
    'timeline_applied' => 'طُبِّقت التغييرات',
    'timeline_task_started' => 'بدأت الوظيفة',
    'timeline_session_started' => 'بدأت جلسة الوكيل',
    'timeline_changeset_ready' => 'التغييرات جاهزة للمراجعة',
    'timeline_planning' => 'التخطيط للتغيير',
    'timeline_reading' => 'قراءة المشروع',
    'timeline_reading_path' => 'قراءة :path',
    'timeline_editing' => 'تعديل الملفات',
    'timeline_updated' => 'حُدِّثت ملفات',
    'timeline_updated_path' => 'حُدِّث :path',
    'timeline_ran_command' => 'شغّل :command',
    'timeline_ran_command_short' => 'شغّل أمرًا',
    'timeline_testing' => 'تشغيل الاختبارات',
    'timeline_plan' => 'شارك خطة',
    'timeline_permission_allowed' => 'سمحت المنصة بإجراء لوقت التشغيل',
    'timeline_permission_refused' => 'رفضت المنصة إجراءً من وقت التشغيل',
    'timeline_message' => 'رسالة من الوكيل',
    'timeline_error' => 'فشلت الوظيفة — :reason',
    'timeline_completed' => 'انتهت الوظيفة',
    'timeline_bounded' => 'تُعرض أحدث :shown من إجمالي :total حدثًا. الأحداث الخام في التفاصيل التقنية.',

    // فشل الوظيفة (G18) — فئات مُصنَّفة، لا تفاصيل خام أبدًا.
    'error_runtime_unavailable' => 'وقت تشغيل الوكيل غير متاح.',
    'error_runtime_auth_failed' => 'رفض وقت تشغيل الوكيل الاتصال.',
    'error_model_unavailable' => 'الموديل المختار غير متاح لدى وقت التشغيل.',
    'error_session_failed' => 'تعذّر إتمام جلسة الوكيل.',
    'error_workspace_failed' => 'تعذّر تحضير مساحة العمل المعزولة.',
    'error_command_failed' => 'فشلت خطوة تحقق أو تطبيق.',
    'error_task_cancelled' => 'أُلغيت الوظيفة.',
    'error_invalid_runtime_response' => 'أعاد وقت تشغيل الوكيل ردًا غير قابل للقراءة.',
    'error_timeout' => 'تجاوزت الوظيفة ميزانيتها الزمنية.',
    'error_generic' => 'تعذّر إتمام الوظيفة.',
    'error_stage_line' => 'حدث هذا في: :stage.',
    'stage_runtime_connection' => 'الاتصال بوقت التشغيل',
    'stage_workspace' => 'تحضير مساحة العمل المعزولة',
    'stage_model' => 'اختيار الموديل',
    'stage_verification' => 'التحقق',
    'stage_session' => 'جلسة الوكيل',
    'stage_cancelled' => 'الإلغاء',

    // حالة وقت التشغيل (G4) + الاستعادة (G3).
    'runtime_connected' => 'وقت التشغيل متصل',
    'runtime_unavailable' => 'وقت التشغيل غير متاح',
    'runtime_needs_configuration' => 'وقت التشغيل يحتاج إعدادًا',
    'recovery_title' => 'لم يُهيَّأ وكيل المطوّر بعد.',
    'recovery_body_admin' => 'هيّئ وقت تشغيل لبدء مهام البرمجة.',
    'recovery_body_user' => 'وكيل المطوّر غير متاح بعد. اطلب من مسؤول تهيئة وقت التشغيل.',
    'recovery_no_project' => 'تحتاج مشروعًا يمكنك تشغيل الوكلاء عليه. أنشئ مشروعًا أو اطلب إضافتك إلى واحد أولًا.',
    'recovery_cta' => 'إعداد وقت التشغيل',

    // بطاقة القرار (G10–G12).
    'decision_title' => 'جاهز للمراجعة',
    'decision_impact' => 'الموافقة تطبّق حزمة التغييرات المراجَعة هذه كما هي في المستودع الرئيسي:',
    'decision_explainer' => 'الموافقة تطبّق حزمة التغييرات المراجَعة هذه كما هي في المستودع الرئيسي ثم تشغّل التحقق.',
    'decision_no_deploy' => 'لن يحدث أي نشر تلقائيًا.',
    'approve_and_apply' => 'موافقة وتطبيق',
    'apply_approved' => 'تطبيق التغييرات الموافق عليها',
    'approve_without_apply_note' => 'دورك يسمح بالموافقة، لكن تطبيق التغييرات الموافق عليها يحتاج مشغلًا يملك صلاحية التطبيق.',
    'apply_pending' => 'حُسمت الموافقة — التطبيق يحتاج مشغلًا يملك صلاحية التطبيق.',
    'reject_note_label' => 'السبب (اختياري)',
    'stale_title' => 'تغيّرت التغييرات المقترحة بعد المراجعة.',
    'stale_body' => 'راجع الفرق الجديد قبل الموافقة مرة أخرى.',

    // الإلغاء (G17).
    'cancel_title' => 'إيقاف هذه الوظيفة؟',
    'cancel_copy' => 'يوقف مهمة الوكيل. التغييرات المطبقة بالفعل لا تُسترجع تلقائيًا.',

    // إعادة المحاولة (G19).
    'retry_new_task' => 'بدء مهمة جديدة من هذا الطلب',

    // السجل (G20) + الحالة الفارغة (G21).
    'filter_label' => 'تصفية الوظائف',
    'filter_all' => 'الكل',
    'filter_active' => 'النشطة',
    'filter_ready' => 'جاهزة للمراجعة',
    'filter_completed' => 'المكتملة',
    'filter_failed' => 'الفاشلة',
    'filter_cancelled' => 'الملغاة',
    'tasks_empty_body' => 'اطلب من وكيل المطوّر إجراء تغيير في مساحة عمل معزولة. تراجع الفرق قبل تطبيق أي شيء.',
    'prompt_disclosure' => 'عرض نص المهمة',

    // الفروقات (G7).
    'diff_files_changed' => 'ملفًا تغيّرت',
    'diff_view' => 'عرض الفرق',

    // التفاصيل التقنية (G15/G16).
    'tech_task_id' => 'معرّف الوظيفة',
    'tech_session_id' => 'معرّف جلسة الوكيل',
    'tech_runtime' => 'وقت التشغيل',
    'tech_workspace_path' => 'مسار مساحة العمل المعزولة',
    'tech_raw_events' => 'الأحداث الخام',
    'tech_approval' => 'الموافقة',
    'tech_approval_consumed' => 'مُستخدمة',
];
