<?php

namespace App\Modules\Invoices\Services;

use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Models\InvoiceLine;
use App\Support\ArabicText;
use App\Support\LogoDataUri;
use App\Support\Money;
use DateTimeImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Server-rendered invoice / credit-note document (ADR-0014).
 *
 * Produces a self-contained A4 PDF from the invoice already stored in the
 * database: company letterhead, bilingual labels, bill-to/supplier block,
 * line items, totals, outstanding balance and FX provenance. It never reads
 * or changes accounting state; the posted journal remains the source of truth.
 *
 * Arabic is shaped through ArabicText because Dompdf does not run the bidi
 * algorithm. The document is deliberately generic (no client hardcoding) and
 * only marks a document as a draft/void; it does not claim tax authority
 * compliance (DEC-028 stays with the accountant).
 */
class InvoiceDocument
{
    /** @var array<string, array{0: string, 1: string}> ar, en */
    private const LABELS = [
        'number' => ['رقم الفاتورة', 'Invoice number'],
        'issue_date' => ['تاريخ الإصدار', 'Issue date'],
        'due_date' => ['تاريخ الاستحقاق', 'Due date'],
        'currency' => ['العملة', 'Currency'],
        'fx_rate' => ['سعر الصرف', 'FX rate'],
        'booking' => ['الحجز', 'Booking'],
        'status' => ['الحالة', 'Status'],
        'bill_to' => ['فاتورة إلى', 'Bill to'],
        'supplier' => ['المورد', 'Supplier'],
        'description' => ['البيان', 'Description'],
        'qty' => ['الكمية', 'Qty'],
        'unit_price' => ['سعر الوحدة', 'Unit price'],
        'tax' => ['الضريبة', 'Tax'],
        'amount' => ['المبلغ', 'Amount'],
        'subtotal' => ['الإجمالي قبل الضريبة', 'Subtotal'],
        'total' => ['الإجمالي', 'Total'],
        'paid' => ['المسدد', 'Paid'],
        'amount_due' => ['المبلغ المستحق', 'Amount due'],
        'credited' => ['المبلغ الدائن', 'Credited amount'],
        'remaining_credit' => ['الرصيد الدائن المتبقي', 'Remaining credit'],
        'credit_note_for' => ['إشعار دائن عن الفاتورة', 'Credit note for'],
        'page' => ['صفحة', 'Page'],
        'generated' => ['أُنشئت في', 'Generated'],
        'notes' => ['ملاحظات', 'Notes'],
        'prepared_by' => ['أعدّها', 'Prepared by'],
        'received_by' => ['استلمها', 'Received by'],
        'branch' => ['الفرع', 'Branch'],
        'phone' => ['هاتف', 'Phone'],
        'email' => ['البريد الإلكتروني', 'Email'],
        'tax_id' => ['الرقم الضريبي', 'Tax ID'],
        'customer_invoice' => ['فاتورة', 'Invoice'],
        'supplier_invoice' => ['فاتورة مشتريات', 'Purchase invoice'],
        'credit_note' => ['إشعار دائن', 'Credit note'],
        'draft' => ['مسودة', 'Draft'],
        'submitted' => ['قيد المراجعة', 'Submitted'],
        'approved' => ['معتمدة', 'Approved'],
        'issued' => ['صادرة', 'Issued'],
        'voided' => ['ملغاة', 'Voided'],
        'draft_note' => ['مسودة غير صادرة.', 'Draft, not issued.'],
        'approved_note' => ['معتمدة ولم تصدر بعد.', 'Approved but not yet issued.'],
        'voided_note' => ['ملغاة — لا يجوز الدفع بموجبها.', 'Voided — do not pay against this document.'],
        'submitted_note' => ['قيد المراجعة — لم تصدر بعد.', 'Submitted for approval — not yet issued.'],
    ];

    public function __construct(private readonly SettingService $settings) {}

    public function pdf(Invoice $invoice, string $language = 'bilingual'): string
    {
        $rtl = in_array($language, ['ar', 'bilingual'], true);

        $dompdf = new Dompdf($this->dompdfOptions());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->html($invoice, $language), 'UTF-8');
        $dompdf->render();

        // Page numbers need the final layout, so they are drawn on the canvas.
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');
        $size = 7.5;
        $pageText = $rtl
            ? '{PAGE_NUM} / {PAGE_COUNT} '.ArabicText::visual($this->rawLabel('page', 'ar'), true)
            : $this->rawLabel('page', 'en').' {PAGE_NUM} / {PAGE_COUNT}';
        $sample = str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], '00', $pageText);
        $width = $metrics->getTextWidth($sample, $font, $size);
        $canvas->page_text(
            ($canvas->get_width() - $width) / 2,
            $canvas->get_height() - 24,
            $pageText,
            $font,
            $size,
            [0.44, 0.5, 0.56],
        );

        return $dompdf->output();
    }

    /** Exposed so tests can assert the document content without parsing the PDF binary. */
    public function html(Invoice $invoice, string $language = 'bilingual'): string
    {
        $invoice->loadMissing('lines.account', 'party', 'branch', 'booking', 'creditNoteOf');

        $rtl = in_array($language, ['ar', 'bilingual'], true);
        $lang = $language === 'en' ? 'en' : 'ar';
        $dir = $rtl ? 'rtl' : 'ltr';
        $align = $rtl ? 'right' : 'left';
        $currency = (string) $invoice->currency_code;

        $logo = LogoDataUri::fromValue((string) $this->settings->get('general', 'company_logo', ''));
        $logoCell = $logo !== null ? '<td class="logo"><img src="'.$logo.'" alt=""></td>' : '<td class="logo"></td>';
        $companyLines = array_map($this->text(...), $this->companyLines($language));
        $companyCell = '<td class="company">'.implode('<br>', $companyLines).'</td>';
        $branchName = $this->branchName($invoice, $language);

        $title = $this->label((string) $invoice->type, $language);
        $statusLabel = $this->label((string) $invoice->status, $language);
        $docCell = '<td class="doc">'
            .'<div class="title">'.$title.'</div>'
            .'<div class="number" dir="ltr">'.$this->escape((string) $invoice->number).'</div>'
            .'<div class="status status-'.$this->escape((string) $invoice->status).'">'.$statusLabel.'</div>'
            .($branchName !== '' ? '<div class="branch">'.$this->label('branch', $language, false).': '.$this->text($branchName).'</div>' : '')
            .'</td>';

        $headerCells = [$logoCell, $companyCell, $docCell];
        // The first cell sits at the reading start: left for LTR, right for RTL.
        if ($rtl) {
            $headerCells = array_reverse($headerCells);
        }

        $statusNote = $this->statusNote((string) $invoice->status, $language);
        $band = $statusNote !== ''
            ? '<div class="status-band status-'.$this->escape((string) $invoice->status).'">'.$statusNote.'</div>'
            : '';

        $meta = [
            [$this->label('issue_date', $language), $this->date($invoice->issue_date?->toDateString())],
            [$this->label('due_date', $language), $this->date($invoice->due_date?->toDateString())],
            [$this->label('currency', $language), $this->escape($currency)],
        ];
        if ($this->isForeign($invoice)) {
            $meta[] = [$this->label('fx_rate', $language), $this->escape($this->rate((string) $invoice->fx_rate).' '.$this->baseCurrency())];
        }
        if ($invoice->booking_id !== null) {
            $meta[] = [$this->label('booking', $language), $this->text((string) ($invoice->booking?->number ?? $invoice->booking_id))];
        }
        if ($invoice->credit_note_of_id !== null) {
            $meta[] = [
                $this->label('credit_note_for', $language),
                $this->escape((string) ($invoice->creditNoteOf?->number ?? $invoice->credit_note_of_id)),
            ];
        }
        $metaRows = '';
        foreach ($meta as [$label, $value]) {
            $metaRows .= '<tr><th>'.$label.'</th><td dir="auto">'.$value.'</td></tr>';
        }

        $party = $invoice->party;
        $partyLabels = $invoice->party_type === 'supplier'
            ? [$this->label('supplier', $language)]
            : [$this->label('bill_to', $language)];
        $partyHtml = '<div class="party-label">'.$partyLabels[0].'</div>';
        if ($party !== null) {
            $partyHtml .= '<div class="party-name">'.$this->text($this->partyName($party, $language)).'</div>';
            $details = [];
            if (! empty($party->phone)) {
                $details[] = $this->label('phone', $language, false).': '.$this->text((string) $party->phone);
            }
            if (! empty($party->email)) {
                $details[] = $this->label('email', $language, false).': '.$this->escape((string) $party->email);
            }
            if (! empty($party->tax_id)) {
                $details[] = $this->label('tax_id', $language, false).': '.$this->escape((string) $party->tax_id);
            }
            if ($details !== []) {
                $partyHtml .= '<div class="party-details">'.implode('<br>', $details).'</div>';
            }
        } else {
            $partyHtml .= '<div class="party-name">—</div>';
        }

        $partyCell = '<td class="party-cell" width="42%">'.$partyHtml.'</td>';
        $metaCell = '<td class="meta-cell" width="58%"><table class="meta">'.$metaRows.'</table></td>';
        $infoCells = [$partyCell, $metaCell];
        if ($rtl) {
            $infoCells = array_reverse($infoCells);
        }

        $header = '<div class="header"><table><tr>'.implode('', $headerCells).'</tr></table></div>';
        $info = '<table class="info"><tr>'.implode('', $infoCells).'</tr></table>';

        $headers = [
            $this->label('description', $language),
            $this->label('qty', $language),
            $this->label('unit_price', $language),
            $this->label('tax', $language),
            $this->label('amount', $language),
        ];
        $widths = ['46%', '8%', '16%', '14%', '16%'];
        $headRow = '';
        foreach ($this->direction($headers, $rtl) as $cell) {
            $headRow .= '<th>'.$cell.'</th>';
        }
        $colgroup = '';
        foreach ($this->direction($widths, $rtl) as $width) {
            $colgroup .= '<col style="width:'.$width.'">';
        }

        $bodyRows = '';
        $lines = $invoice->lines->sortBy('id')->values();
        foreach ($lines as $index => $line) {
            /** @var InvoiceLine $line */
            $amount = (int) $line->debit_minor + (int) $line->credit_minor;
            $quantity = $line->quantity !== null ? (string) $line->quantity : '';
            $unit = $line->unit_price_minor !== null
                ? $this->amount((int) $line->unit_price_minor, $currency)
                : '—';
            $cells = [
                ['html' => '<div class="line-description">'.$this->text($line->description ?? $this->rawLabel('description', $language)).'</div>', 'num' => false],
                ['html' => $this->escape($quantity), 'num' => true],
                ['html' => $unit, 'num' => true],
                ['html' => $this->amount((int) $line->tax_minor, $currency), 'num' => true],
                ['html' => $this->amount($amount, $currency), 'num' => true],
            ];
            $bodyRows .= '<tr'.($index % 2 === 1 ? ' class="alt"' : '').'>';
            foreach ($this->direction($cells, $rtl) as $cell) {
                $bodyRows .= '<td'.($cell['num'] ? ' class="num"' : '').'>'.$cell['html'].'</td>';
            }
            $bodyRows .= '</tr>';
        }
        if ($bodyRows === '') {
            $bodyRows = '<tr><td colspan="5" class="empty">—</td></tr>';
        }

        $total = (int) $invoice->total_minor;
        $outstanding = (int) $invoice->outstanding_minor;
        $applied = max(0, $total - $outstanding);
        $totals = [
            [$this->label('subtotal', $language), $this->amount((int) $invoice->subtotal_minor, $currency), false],
            [$this->label('tax', $language), $this->amount((int) $invoice->tax_minor, $currency), false],
            [$invoice->type === Invoice::TYPE_CREDIT_NOTE ? $this->label('credited', $language) : $this->label('total', $language), $this->amount($total, $currency), true],
        ];
        if ($invoice->type === Invoice::TYPE_CREDIT_NOTE) {
            $totals[] = [$this->label('paid', $language), $this->amount($applied, $currency), false];
            $totals[] = [$this->label('remaining_credit', $language), $this->amount($outstanding, $currency), false];
        } else {
            $totals[] = [$this->label('paid', $language), $this->amount($applied, $currency), false];
            $totals['due'] = [$this->label('amount_due', $language), $this->amount($outstanding, $currency), true];
        }
        $totalsRows = '';
        foreach ($totals as $key => [$label, $value, $strong]) {
            $totalsRows .= '<tr'.($key === 'due' ? ' class="due"' : ($strong ? ' class="strong"' : '')).'><th>'.$label.'</th><td>'.$value.'</td></tr>';
        }
        $notes = '';
        if (! empty($invoice->memo)) {
            $notes = '<div class="notes"><div class="notes-label">'.$this->label('notes', $language).'</div>'
                .'<div>'.$this->text((string) $invoice->memo).'</div></div>';
        }
        $fxNote = $this->isForeign($invoice)
            ? '<div class="fx-note">'.'1 '.$this->escape($currency).' = '.$this->escape($this->rate((string) $invoice->fx_rate)).' '.$this->escape($this->baseCurrency())
                .($invoice->fx_rate_date ? ' · '.$this->escape($invoice->fx_rate_date->toDateString()) : '').'</div>'
            : '';

        $signCells = [
            $this->label('prepared_by', $language),
            $this->label('received_by', $language),
        ];
        $signHtml = '';
        foreach ($this->direction($signCells, $rtl) as $cell) {
            $signHtml .= '<td>'.$cell.'<div class="line"></div></td>';
        }

        $generated = $this->generatedAt();
        $footerCompany = $this->text($this->companyLines($language)[0] ?? '');
        $footer = '<div class="footer">'.$footerCompany.' · '.$this->label('generated', $language, false).' '.$this->escape($generated).'</div>';

        $style = '@page{margin:32px 32px 44px 32px;size:A4 portrait;}'
            .'body{font-family:"DejaVu Sans",sans-serif;font-size:9px;color:#172033;direction:ltr}'
            .'table{width:100%;border-collapse:collapse}'
            .'td,th{padding:0;border:0}'
            .'.header{border-bottom:3px solid #1f3a5f;padding-bottom:10px}'
            .'.header td{vertical-align:top;padding:0 6px}'
            .'.sub{font-size:7px;font-weight:normal;color:#718096}'
            .'.header .logo{width:22%}.header .logo img{max-height:54px;max-width:150px}'
            .'.header .company{width:40%;font-size:15px;color:#1f3a5f;font-weight:bold;text-align:'.$align.'}'
            .'.header .doc{width:38%;text-align:'.($rtl ? 'left' : 'right').'}'
            .'.header .doc .title{font-size:19px;font-weight:bold;color:#1f3a5f}'
            .'.header .doc .number{font-size:11px;margin-top:2px}'
            .'.header .doc .status{display:inline-block;margin-top:5px;padding:3px 10px;border:1px solid #1f3a5f;color:#1f3a5f;font-size:8px;font-weight:bold}'
            .'.header .doc .status-draft{border-color:#b7791f;color:#b7791f;background:#fffaf0}'
            .'.header .doc .status-voided{border-color:#c53030;color:#c53030;background:#fff5f5}'
            .'.header .doc .status-issued,.header .doc .status-approved{border-color:#2f855a;color:#2f855a;background:#f0fff4}'
            .'.header .doc .branch{font-size:8px;color:#4a5568;margin-top:4px}'
            .'.status-band{margin:10px 0 0 0;padding:5px 8px;border:1px solid #b8c0cc;background:#f4f6f9;font-size:9px;font-weight:bold;text-align:'.$align.'}'
            .'.status-band.status-voided{background:#fbe9e9;border-color:#d9a3a3}'
            .'.status-band.status-draft{background:#fff7e0;border-color:#e0c97a}'
            .'.info{margin-top:12px}'
            .'.info .party-cell,.info .meta-cell{vertical-align:top;padding:10px;border:1px solid #d8dee9;background:#f8fafc}'
            .'.party-label{font-size:8px;color:#718096;margin-bottom:3px}'
            .'.party-name{font-size:12px;font-weight:bold;color:#1f3a5f}'
            .'.party-details{font-size:8px;color:#4a5568;margin-top:3px}'
            .'.meta th{text-align:'.$align.';font-weight:normal;color:#4a5568;padding:3px 0;white-space:nowrap}'
            .'.meta td{text-align:'.($rtl ? 'left' : 'right').';padding:3px 0 3px 6px;font-weight:bold}'
            .'table.data{margin-top:14px}'
            .'table.data th,table.data td{border:1px solid #d8dee9;padding:4px 5px;vertical-align:top;text-align:'.$align.'}'
            .'table.data th{background:#1f3a5f;color:#ffffff;border-color:#1f3a5f;font-weight:bold;font-size:8px;padding:6px 5px}'
            .'table.data tbody tr.alt td{background:#f8fafc}'
            .'table.data thead{display:table-header-group}'
            .'table.data tr{page-break-inside:avoid}'
            .'table.data td.num,table.data th.num{text-align:right;white-space:nowrap}'
            .'table.data td{padding:6px 5px}'
            .'table.data td.empty{text-align:center;color:#718096}'
            .'table.totals{width:52%;margin-top:10px;'.($rtl ? 'margin-left:0;margin-right:auto' : 'margin-left:auto').'}'
            .'table.totals th,table.totals td{border:1px solid #d8dee9;padding:4px 6px;font-weight:normal}'
            .'table.totals th{text-align:'.$align.';color:#4a5568}'
            .'table.totals td{text-align:right;white-space:nowrap}'
            .'table.totals tr.strong th,table.totals tr.strong td{font-weight:bold;background:#eef2f7;color:#1f3a5f}'
            .'table.totals tr.due th,table.totals tr.due td{font-weight:bold;font-size:11px;background:#1f3a5f;color:#ffffff;border-color:#1f3a5f}'
            .'table.totals tr.due .sub{color:#cbd5e0}'
            .'.notes{margin-top:12px;font-size:8px;border-top:1px solid #d8dee9;padding-top:6px}'
            .'.notes-label{color:#4a5568;margin-bottom:2px}'
            .'.fx-note{margin-top:6px;font-size:8px;color:#4a5568}'
            .'.signoff{margin-top:26px;font-size:9px;page-break-inside:avoid}'
            .'.signoff td{padding:0 14px;text-align:'.$align.';color:#4a5568;width:50%}'
            .'.signoff .line{border-bottom:1px solid #94a3b8;height:30px}'
            .'.footer{margin-top:22px;padding-top:6px;border-top:1px solid #d8dee9;font-size:7px;color:#718096;text-align:center}';

        return '<!doctype html><html lang="'.$lang.'" dir="'.$dir.'"><head><meta charset="UTF-8"><style>'.$style.'</style></head><body>'
            .$header
            .$band
            .$info
            .'<table class="data"><colgroup>'.$colgroup.'</colgroup><thead><tr>'.$headRow.'</tr></thead><tbody>'.$bodyRows.'</tbody></table>'
            .'<table class="totals">'.$totalsRows.'</table>'
            .$notes
            .$fxNote
            .'<table class="signoff"><tr>'.$signHtml.'</tr></table>'
            .$footer
            .'</body></html>';
    }

    /** @return array<int, string> */
    private function companyLines(string $language): array
    {
        $ar = trim((string) $this->settings->get('general', 'company_name_ar', ''));
        $en = trim((string) $this->settings->get('general', 'company_name_en', ''));

        return match ($language) {
            'en' => [$en !== '' ? $en : $ar],
            'ar' => [$ar !== '' ? $ar : $en],
            default => array_values(array_filter([$ar, $en], static fn (string $name): bool => $name !== '')),
        };
    }

    private function branchName(Invoice $invoice, string $language): string
    {
        $branch = $invoice->branch;
        if ($branch === null) {
            return '';
        }

        $ar = (string) $branch->name_ar;
        $en = (string) $branch->name_en;

        return match ($language) {
            'en' => $en !== '' ? $en : $ar,
            'ar' => $ar !== '' ? $ar : $en,
            default => implode(' / ', array_unique(array_filter([$ar, $en]))),
        };
    }

    private function partyName(object $party, string $language): string
    {
        $ar = (string) ($party->name_ar ?? '');
        $en = (string) ($party->name_en ?? '');

        return match ($language) {
            'en' => $en !== '' ? $en : $ar,
            'ar' => $ar !== '' ? $ar : $en,
            default => implode(' / ', array_unique(array_filter([$ar, $en]))),
        };
    }

    private function statusNote(string $status, string $language): string
    {
        return match ($status) {
            Invoice::STATUS_DRAFT => $this->label('draft_note', $language),
            Invoice::STATUS_SUBMITTED => $this->label('submitted_note', $language),
            Invoice::STATUS_APPROVED => $this->label('approved_note', $language),
            Invoice::STATUS_VOIDED => $this->label('voided_note', $language),
            default => '',
        };
    }

    /** Plain logical-order label, for callers that shape the text themselves. */
    private function rawLabel(string $key, string $language): string
    {
        [$ar, $en] = self::LABELS[$key] ?? [$key, $key];

        return match ($language) {
            'en' => $en,
            'ar' => $ar,
            default => $ar.' / '.$en,
        };
    }

    /**
     * Label as safe HTML: Arabic shaped for the renderer and, in a bilingual
     * document, the English text on a smaller second line (or, when
     * $stack is false, Arabic only, for labels that sit inside a sentence).
     */
    private function label(string $key, string $language, bool $stack = true): string
    {
        if ($language !== 'bilingual') {
            return $this->text($this->rawLabel($key, $language));
        }
        [$ar, $en] = self::LABELS[$key] ?? [$key, $key];
        if (! $stack) {
            // Inline labels stay Arabic-only: the values next to them are already bilingual.
            return $this->text($ar);
        }

        return $this->text($ar).'<br><span class="sub">'.$this->escape($en).'</span>';
    }

    private function amount(int $minor, string $currency): string
    {
        return $this->escape($this->groupDigits((new Money($minor, $currency))->toDecimal()));
    }

    private function rate(string $rate): string
    {
        $trimmed = rtrim(rtrim($rate, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    private function isForeign(Invoice $invoice): bool
    {
        return strtoupper((string) $invoice->currency_code) !== $this->baseCurrency();
    }

    private function baseCurrency(): string
    {
        return strtoupper((string) $this->settings->baseCurrency());
    }

    private function generatedAt(): string
    {
        $timezone = (string) $this->settings->get('general', 'timezone', 'UTC');
        try {
            return (new DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d H:i');
        } catch (\Exception) {
            return gmdate('Y-m-d H:i');
        }
    }

    private function date(?string $value): string
    {
        return $value === null || $value === '' ? '—' : $this->escape($value);
    }

    /** 1234567.50 => 1,234,567.50 (string-based; no float). */
    private function groupDigits(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        $parts = explode('.', ltrim($decimal, '-'), 2);
        $int = strrev(implode(',', str_split(strrev($parts[0]), 3)));

        return ($negative ? '-' : '').$int.(isset($parts[1]) ? '.'.$parts[1] : '');
    }

    /** Arabic text in visual order for the left-to-right PDF renderer. */
    private function text(string $value): string
    {
        return $this->escape(ArabicText::visual($value));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array<int, mixed>
     */
    private function direction(array $cells, bool $rtl): array
    {
        return $rtl ? array_reverse($cells) : $cells;
    }

    private function dompdfOptions(): Options
    {
        $cache = storage_path('framework/cache/dompdf');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('allowedProtocols', ['data://' => ['rules' => []]]);
        if (is_dir($cache) && is_writable($cache)) {
            $options->set('fontCache', $cache);
        }

        return $options;
    }
}
