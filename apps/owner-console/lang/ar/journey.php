<?php

// ── حالات الرحلة — 0.4.0-rc.5 (المرحلة 41) ───────────────────────────

return [

    'state_not_started' => 'لم تبدأ',
    'state_in_progress' => 'قيد التنفيذ',
    'state_ready' => 'جاهز',
    'state_needs_attention' => 'يحتاج انتباهًا',
    'state_blocked' => 'محجوب',
    'state_complete' => 'مكتمل',

    // مراحل رحلة الترحيل (مكوّن x-nx-journey).
    'stage_connect' => 'الاتصال',
    'stage_analyze' => 'التحليل',
    'stage_migrate' => 'الترحيل',
    'stage_plan' => 'الخطة',
    'stage_sync' => 'المزامنة الحية',
    'stage_validate' => 'التحقق',
    'stage_cutover' => 'التحويل النهائي',

    'desc_connect' => 'اربط المصدر الذي تُرحّل منه.',
    'desc_analyze' => 'جرد الجداول والصفوف والتوافق.',
    'desc_plan' => 'راجع الخطة والتغييرات التي ستجريها.',
    'desc_migrate' => 'انقل مجموعة البيانات الأولية.',
    'desc_sync' => 'أبقِ التغييرات متدفقة أثناء الاستعداد للتبديل.',
    'desc_validate' => 'أكّد تطابق البيانات.',
    'desc_cutover' => 'حوّل الإنتاج مع جهوزية التراجع.',
];
