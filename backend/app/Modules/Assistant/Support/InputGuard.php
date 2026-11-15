<?php

namespace App\Modules\Assistant\Support;

/**
 * First line of defence on what the user types (ADR-0030). The assistant has no shell, no network and no
 * tool outside its allowlist, so these requests could never run; refusing them before the provider is called
 * keeps them out of the model's hands, costs nothing, and leaves an audit row. It catches the plain attempts
 * (override the rules, reveal the instructions, run a system command, skip the confirmation). The real
 * guarantees stay where they are: the tool allowlist, the user's own permissions, and the confirmation card.
 */
final class InputGuard
{
    /** @var list<string> */
    private const PATTERNS = [
        // Override or reveal the rules.
        '/\b(ignore|disregard|forget|override|bypass)\b[^.\n]{0,30}\b(instructions?|rules?|guidelines?|restrictions?|system prompt|safeguards?)\b/iu',
        '/\b(reveal|show|print|repeat|leak)\b[^.\n]{0,25}\b(system|hidden|initial|developer)\b[^.\n]{0,10}\b(prompt|instructions?|message)\b/iu',
        '/\b(developer mode|jailbreak|DAN mode|god mode)\b/iu',
        '/(تجاهل|تخطّ?ى?|انس[ىي]?|تجاوز|الغ[يى]?)\s+(كل\s+|جميع\s+)?(التعليمات|القواعد|الضوابط|القيود|الحماية)/u',
        '/(اظهر|أظهر|اعرض|اطبع|كرر)\s+(لي\s+)?(تعليمات|برومبت|prompt)\s*(النظام|الداخلية|المخفية)?/u',
        // Run something outside the assistant.
        '/\b(rm\s+-rf|sudo\s|chmod\s|chown\s|wget\s|curl\s+-|bash\s+-c|sh\s+-c|powershell|cmd\.exe|\/bin\/(ba)?sh|exec\s*\(|eval\s*\(|system\s*\(|artisan\s+\w+|DROP\s+TABLE|TRUNCATE\s+TABLE)/iu',
        '/(نفّ?ذ|شغّ?ل)\s+(لي\s+)?(هذا\s+)?(الأمر|امر|الكود|السكريبت)\s*(على\s+)?(السيرفر|الخادم|الطرفية|terminal|shell)/u',
        // Skip the confirmation, the approval or the permission check.
        '/\b(skip|bypass|disable|turn off)\b[^.\n]{0,15}\b(confirm(ation)?|approval|permissions?|authori[sz]ation)\b/iu',
        '/\b(do it|run it|post|pay|approve|send|delete|void|issue|execute)\b[^.\n]{0,40}\bwithout\b[^.\n]{0,12}\b(confirm(ation)?|asking|approval)\b/iu',
        '/(تخطّ?ى?|تجاوز|الغ[يى]?|عطّ?ل)\s+(ال)?(تأكيد|موافقة|الموافقة|صلاحية|الصلاحيات|اعتماد)/u',
        '/(نفّ?ذ|اعتمد|رحّ?ل|ادفع|احذف|ارسل|أرسل|الغ[يى]?)\s[^.\n]{0,40}(بدون|من غير|دون)\s+(ال)?(تأكيد|موافقة|سؤال)/u',
    ];

    public static function blocks(string $text): bool
    {
        $text = mb_substr($text, 0, 4000);
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function refusal(string $locale): string
    {
        return $locale === 'ar'
            ? 'لا أستطيع تنفيذ هذا الطلب. أساعدك في بيانات النظام فقط، وأي تغيير يحتاج تأكيدك أنت على البطاقة.'
            : 'I cannot do that. I only help with the data in this system, and every change needs your confirmation on the card.';
    }
}
