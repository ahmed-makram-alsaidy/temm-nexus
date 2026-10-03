<?php

// ── Nexus AI (المساعد + الإعدادات + المساعد داخل المشاريع) — rc.5 ────

return [

    'title' => 'Nexus AI',
    'subtitle' => 'اسأل عن منصتك أو مساحة عمل عميل أو مشروع واحد. لا يرى المساعد ولا يفعل إلا ما تملك أنت صلاحية رؤيته أو فعله.',

    // واجهة المساعد.
    'your_question' => 'سؤالك',
    'send' => 'إرسال',
    'quick_actions' => 'إجراءات سريعة',
    'model_routing' => 'توجيه الموديلات',
    'answer_with' => 'أجب باستخدام',
    'context' => 'السياق',
    'inspect_mode' => 'وضع الفحص',
    'actions_and_approvals' => 'الإجراءات والموافقات',
    'affected' => 'المتأثر',
    'applies_to' => 'ينطبق على',
    'choose_a_change' => 'اختر تغييرًا…',
    'component' => 'المكوّن',
    'current' => 'الحالي',
    'proposed' => 'المقترح',
    'plan' => 'الخطة',
    'proposed_appearance_change' => 'تغيير مظهر مقترح',
    'what_it_does' => 'ماذا يفعل',
    'what_the_assistant_can_read_here' => 'ما يمكن للمساعد قراءته هنا',
    'selected' => 'المحدد',
    'not_enabled_in_this_build' => 'غير مُفعّل في هذا الإصدار',
    'not_configured_yet' => 'لم يُعد Nexus AI بعد.',
    'not_configured_body' => 'يمكن لمشغّل ربط مزوّد من الإعدادات ← إعدادات Nexus AI. وحتى ذلك الحين يبقى المساعد متاحًا للتنقل فقط.',
    'no_permission_scope' => 'لا تملك صلاحية استخدام Nexus AI في هذا النطاق.',

    // أدوار الموديلات (A.5 — بلغة منتج مفهومة).
    'role_default' => 'المساعد الافتراضي',
    'role_reasoning' => 'التشخيص العميق',
    'role_code' => 'تغييرات الكود',

    // مقترحات تفضيلات الواجهة (وضع الفحص).
    'hide_this_component' => 'إخفاء هذا المكوّن',
    'show_this_component' => 'إظهار هذا المكوّن',
    'density_compact' => 'كثافة مضغوطة',
    'density_comfortable' => 'كثافة مريحة',
    'density_spacious' => 'كثافة واسعة',
    'start_expanded' => 'يبدأ موسعًا',
    'start_collapsed' => 'يبدأ مطويًا',
    'move_to_first' => 'نقله إلى الأول',
    'move_to_last' => 'نقله إلى الأخير',
    'value_starts_expanded' => 'يبدأ موسعًا',
    'value_starts_collapsed' => 'يبدأ مطويًا',
    'value_shown' => 'ظاهر',
    'value_hidden' => 'مخفي',
    'density_label_compact' => 'مضغوط',
    'density_label_comfortable' => 'مريح',
    'density_label_spacious' => 'واسع',
    'position_first' => 'الأول',
    'position_last' => 'الأخير',
    'position_natural' => 'الموضع الطبيعي',

    'scope_you_in_project' => 'أنت، في مشروع :name',
    'scope_you_in_workspace' => 'أنت، في مساحة عمل :name',

    // ── الإعدادات ← Nexus AI (A.1–A.8) ──────────────────────────────
    'settings_title' => 'إعدادات Nexus AI',
    'settings_subtitle' => 'أعد مزوّد الذكاء الاصطناعي الذي يستخدمه Nexus AI. تُشفَّر المفاتيح في التخزين ولا تُعرض مرة أخرى أبدًا.',
    'nav_settings' => 'إعدادات Nexus AI',

    'ai_master_enabled' => 'تفعيل الذكاء الاصطناعي',
    'ai_master_helper' => 'المفتاح الرئيسي للمساعد في كل المنصة. عند الإيقاف يختفي Nexus AI من كل الواجهات حتى يُعاد تفعيله.',

    'status_title' => 'الحالة الحالية',
    'status_provider' => 'المزوّد',
    'status_model' => 'الموديل',
    'status_last_test' => 'آخر اختبار ناجح',
    'status_last_test_never' => 'لم يُختبر بعد',
    'status_last_error' => 'آخر خطأ آمن',
    'status_none_recorded' => 'لا يوجد ما يُسجَّل',
    'status_scope' => 'نطاق الإعداد',
    'status_scope_platform' => 'يستخدم الإعداد الافتراضي للمنصة',
    'status_scope_project' => 'هناك مشروع لديه مزوّد خاص به (:count إجمالاً). يرث مستخدمو المشاريع الإعداد الافتراضي للمنصة ما لم يعرّف المشروع مزوّده الخاص.',

    'provider_section_title' => 'المزوّد',
    'provider_section_help' => 'مزوّد واحد وموديل واحد كافيان. أدوار الموديلات المتقدمة اختيارية.',
    'provider' => 'المزوّد',
    'display_name' => 'اسم العرض',
    'base_url' => 'العنوان الأساسي (Base URL)',
    'base_url_helper' => 'مطلوب لنقاط النهاية المتوافقة مع OpenAI. HTTPS فقط.',
    'default_model' => 'الموديل الافتراضي',
    'api_key' => 'مفتاح API',
    'api_key_helper' => 'يُخزَّن مشفَّرًا في خزنة المنصة. بعد الحفظ يظهر تلميح مُقنَّع فقط — لن يُعرض المفتاح كاملًا مرة أخرى أبدًا.',
    'api_key_saved_mask' => 'المفتاح المحفوظ: :mask — اتركه فارغًا للإبقاء عليه.',
    'api_key_required' => 'أدخل مفتاح API لحفظ هذا المزوّد.',
    'timeout' => 'مهلة الطلب (ثوانٍ)',
    'max_output_tokens' => 'الحد الأقصى لمخارج الموديل (Tokens)',

    'routing_section_title' => 'أدوار الموديلات',
    'routing_section_help' => 'اختياري. اترك أي دور فارغًا ليرث الموديل الافتراضي للمزوّد. مزوّد واحد وموديل واحد يكفيان لكل شيء.',
    'routing_inherit' => 'يرث الافتراضي من المزوّد',
    'routing_source_provider_default' => 'افتراضي المزوّد',
    'routing_source_profile' => 'تجاوز الدور',
    'routing_source_fallback' => 'مزوّد بديل',
    'routing_source_unconfigured' => 'غير مُعد',

    'test_section_title' => 'اختبار الاتصال',
    'test_button' => 'اختبار الاتصال',
    'test_running' => 'جارٍ الاختبار…',
    'test_ok' => 'تم الاتصال بنجاح',
    'test_result_provider' => 'المزوّد: :provider',
    'test_result_model' => 'الموديل: :model',

    'save_provider' => 'حفظ المزوّد',
    'provider_saved' => 'تم حفظ المزوّد. مفتاح API مخزَّن مشفَّرًا.',
    'enabled_toggled' => 'تم حفظ تفضيل الذكاء الاصطناعي.',
    'routing_saved' => 'تم حفظ أدوار الموديلات.',
    'routing_section' => 'أدوار الموديلات',

    // رسائل نتائج اختبار الاتصال الآمنة (A.6 — بلا stack traces ولا أسرار).
    'test_connected' => 'تم الاتصال بنجاح. قبِل المزوّد المفتاح والموديل.',
    'test_auth_failed' => 'رفض المزوّد مفتاح API. تحقق من المفتاح وحاول مجددًا.',
    'test_model_not_found' => 'رفض المزوّد اسم الموديل. تحقق من معرّف الموديل.',
    'test_rate_limited' => 'المزوّد يفرض حدًا على الطلبات لهذا المفتاح. حاول بعد قليل.',
    'test_timeout' => 'لم يستجب المزوّد في الوقت المحدد. تحقق من الشبكة أو العنوان الأساسي.',
    'test_provider_error' => 'أعاد المزوّد خطأ. قد يكون المفتاح مقبولًا أو الخدمة متدهورة — حاول لاحقًا.',
    'test_disabled' => 'هذا المزوّد مُعطّل.',

    'fake_provider_note' => 'وضع الاختبار/التطوير مُفعّل: المزوّدون الوهميون ظاهرون. يخفون تلقائيًا في بيئة الإنتاج.',
];
