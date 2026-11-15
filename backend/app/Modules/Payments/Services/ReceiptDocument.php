<?php

namespace App\Modules\Payments\Services;

use App\Modules\Invoices\Models\InvoiceApplication;
use App\Modules\Invoices\Services\InvoiceDocument;
use App\Modules\Payments\Models\Payment;
use App\Modules\Treasury\Models\CashAccount;
use App\Support\LogoDataUri;
use App\Support\Money;

/**
 * Server-rendered receipt voucher (سند قبض) for one customer receipt, in the same letterhead, fonts and bilingual
 * layout as the invoice (ADR-0035): who paid, how much, into which account, by which method, on what date, what it
 * was applied to, and signature lines. It reads the stored receipt only; the posted journal stays the source of truth.
 * A cancelled receipt is stamped as cancelled, so a copy can never pass as a valid one.
 */
class ReceiptDocument extends InvoiceDocument
{
    protected const LABELS = parent::LABELS + [
        'receipt_voucher' => ['سند قبض', 'Receipt voucher'],
        'received_from' => ['استلمنا من', 'Received from'],
        'amount_received' => ['المبلغ المستلم', 'Amount received'],
        'received_on' => ['تاريخ الاستلام', 'Received on'],
        'method' => ['طريقة الدفع', 'Payment method'],
        'into_account' => ['الحساب المستلم', 'Received into'],
        'reference' => ['المرجع', 'Reference'],
        'base_equivalent' => ['المعادل بالعملة الأساسية', 'Base-currency equivalent'],
        'applied_to' => ['تم تطبيقه على', 'Applied to'],
        'invoice' => ['الفاتورة', 'Invoice'],
        'advance' => ['دفعة مقدمة غير مخصصة', 'Unallocated advance'],
        'cancelled' => ['ملغى', 'Cancelled'],
        'cancelled_note' => ['سند ملغى — لا يُعتد به.', 'Cancelled receipt — not valid.'],
        'cash' => ['نقداً', 'Cash'],
        'bank_transfer' => ['تحويل بنكي', 'Bank transfer'],
        'card' => ['بطاقة', 'Card'],
        'wallet' => ['محفظة إلكترونية', 'Mobile wallet'],
        'customer_code' => ['كود العميل', 'Customer code'],
    ];

    public function receiptPdf(Payment $payment, string $language = 'bilingual'): string
    {
        return $this->render($this->receiptHtml($payment, $language), in_array($language, ['ar', 'bilingual'], true));
    }

    /** Exposed so tests can assert the document content without parsing the PDF binary. */
    public function receiptHtml(Payment $payment, string $language = 'bilingual'): string
    {
        $payment->loadMissing('customer', 'booking', 'branch', 'cashAccount', 'paymentMethod');

        $rtl = in_array($language, ['ar', 'bilingual'], true);
        $lang = $language === 'en' ? 'en' : 'ar';
        $align = $rtl ? 'right' : 'left';
        $currency = (string) $payment->currency_code;
        $cancelled = $payment->status === 'cancelled';
        $foreign = strtoupper($currency) !== $this->baseCurrency();

        $logo = LogoDataUri::fromValue((string) $this->settings->get('general', 'company_logo', ''));
        $logoCell = '<td class="logo">'.($logo !== null ? '<img src="'.$logo.'" alt="">' : '').'</td>';
        $companyLines = array_map($this->text(...), $this->companyLines($language));
        $companyCell = '<td class="company"><div class="name">'.implode('<br>', $companyLines).'</div>'.$this->companyDetails($language).'</td>';
        $docCell = '<td class="doc"><div class="title">'.$this->label('receipt_voucher', $language).'</div>'
            .'<div class="number" dir="ltr">'.$this->escape((string) $payment->number).'</div>'
            .($cancelled ? '<div class="status status-voided">'.$this->label('cancelled', $language).'</div>' : '')
            .'</td>';
        $cells = [$logoCell, $companyCell, $docCell];
        $header = '<div class="header"><table><tr>'.implode('', $this->direction($cells, $rtl)).'</tr></table></div>';
        $band = $cancelled ? '<div class="status-band status-voided">'.$this->label('cancelled_note', $language).'</div>' : '';

        $customer = $payment->customer;
        $partyHtml = '<div class="party-label">'.$this->label('received_from', $language).'</div>'
            .'<div class="party-name">'.$this->text($customer !== null ? $this->partyName($customer, $language) : '—').'</div>';
        $details = [];
        if ($customer !== null && ! empty($customer->code)) {
            $details[] = $this->inline('customer_code', (string) $customer->code, $language);
        }
        if ($customer !== null && ! empty($customer->phone)) {
            $details[] = $this->inline('phone', (string) $customer->phone, $language);
        }
        if ($details !== []) {
            $partyHtml .= '<div class="party-details">'.implode('<br>', $details).'</div>';
        }

        $meta = [[$this->label('received_on', $language), $this->date($payment->paid_at?->toDateString())]];
        $methodKey = (string) ($payment->method ?? '');
        $methodName = $payment->paymentMethod !== null
            ? ($language === 'en' ? ($payment->paymentMethod->name_en ?: $payment->paymentMethod->name_ar) : ($payment->paymentMethod->name_ar ?: $payment->paymentMethod->name_en))
            : (isset(static::LABELS[$methodKey]) ? $this->rawLabel($methodKey, $language) : $methodKey);
        if ($methodName !== '') {
            $meta[] = [$this->label('method', $language), $this->text($methodName)];
        }
        $account = CashAccount::query()->where('account_id', $payment->cash_account_id)->first();
        if ($account !== null || $payment->cashAccount !== null) {
            $name = $account !== null
                ? ($language === 'en' ? ($account->name_en ?: $account->name_ar) : ($account->name_ar ?: $account->name_en))
                : (string) $payment->cashAccount->name_ar;
            if ($account !== null && ! empty($account->bank_name)) {
                $name .= ' - '.$account->bank_name.(! empty($account->account_last4) ? ' ****'.$account->account_last4 : '');
            }
            $meta[] = [$this->label('into_account', $language), $this->text($name)];
        }
        if ($payment->booking !== null) {
            $meta[] = [$this->label('booking', $language), $this->text((string) $payment->booking->number)];
        }
        if ($foreign) {
            $meta[] = [$this->label('fx_rate', $language), $this->escape('1 '.$currency.' = '.$this->rate((string) $payment->fx_rate).' '.$this->baseCurrency())];
        }
        $metaRows = '';
        foreach ($meta as [$label, $value]) {
            $metaRows .= '<tr><th>'.$label.'</th><td dir="auto">'.$value.'</td></tr>';
        }
        $infoCells = [
            '<td class="party-cell" width="42%">'.$partyHtml.'</td>',
            '<td class="meta-cell" width="58%"><table class="meta">'.$metaRows.'</table></td>',
        ];
        $info = '<table class="info"><tr>'.implode('', $this->direction($infoCells, $rtl)).'</tr></table>';

        $amount = '<table class="amount-box"><tr><th>'.$this->label('amount_received', $language).'</th>'
            .'<td dir="ltr">'.$this->amount((int) $payment->amount_minor, $currency).' '.$this->escape($currency).'</td></tr>'
            .($foreign ? '<tr><th>'.$this->label('base_equivalent', $language).'</th><td dir="ltr">'
                .$this->escape($this->baseEquivalent($payment)).' '.$this->escape($this->baseCurrency()).'</td></tr>' : '')
            .'</table>';

        $applications = InvoiceApplication::query()->where('payment_id', $payment->id)->whereNull('reversed_at')->with('invoice')->orderBy('id')->get();
        $rows = '';
        foreach ($applications as $application) {
            $rows .= '<tr><td>'.$this->label('invoice', $language, false).' '.$this->escape((string) ($application->invoice?->number ?? '—')).'</td>'
                .'<td class="num" dir="ltr">'.$this->amount((int) $application->amount_minor, $currency).' '.$this->escape($currency).'</td></tr>';
        }
        $unapplied = (int) $payment->amount_minor - (int) $applications->sum('amount_minor') - (int) $payment->allocations->sum('amount_minor');
        if ($applications->isEmpty() && $payment->booking === null && (int) $payment->amount_minor > 0) {
            $rows .= '<tr><td>'.$this->label('advance', $language, false).'</td><td class="num" dir="ltr">'.$this->amount((int) $payment->amount_minor, $currency).' '.$this->escape($currency).'</td></tr>';
        } elseif ($unapplied > 0 && $applications->isNotEmpty()) {
            $rows .= '<tr><td>'.$this->label('advance', $language, false).'</td><td class="num" dir="ltr">'.$this->amount($unapplied, $currency).' '.$this->escape($currency).'</td></tr>';
        }
        $appliedTable = $rows === '' ? '' : '<div class="section-label">'.$this->label('applied_to', $language).'</div><table class="data applied">'.$rows.'</table>';

        $signHtml = '';
        foreach ($this->direction([$this->label('prepared_by', $language), $this->label('received_by', $language)], $rtl) as $cell) {
            $signHtml .= '<td>'.$cell.'<div class="line"></div></td>';
        }
        $footer = '<div class="footer">'.$this->text(($this->companyLines($language)[0] ?? '').' · '.$this->inlineLabel('generated', $language).' '.$this->generatedAt()).'</div>';

        $style = '@page{margin:32px 32px 44px 32px;size:A4 portrait;}'
            .$this->fontFaces()
            .'body{font-family:"Suisse Intl",sans-serif;font-size:10px;color:#172033;direction:ltr}'
            .'.ar{font-family:"Noto Sans Arabic","Suisse Intl",sans-serif;font-size:1.08em}'
            .'table{width:100%;border-collapse:collapse}td,th{padding:0;border:0}'
            .'.sub{font-size:7px;font-weight:normal;color:#718096}'
            .'.header{border-bottom:3px solid #1f3a5f;padding-bottom:10px}.header td{vertical-align:top;padding:0 6px}'
            .'.header .logo{width:22%}.header .logo img{max-height:54px;max-width:150px}'
            .'.header .company{width:40%;text-align:'.$align.'}.header .company .name{font-size:15px;color:#1f3a5f;font-weight:bold}'
            .'.header .company .details{margin-top:5px;font-size:8px;color:#4a5568;line-height:1.5}'
            .'.header .doc{width:38%;text-align:'.($rtl ? 'left' : 'right').'}.header .doc .title{font-size:19px;font-weight:bold;color:#1f3a5f}'
            .'.header .doc .number{font-size:11px;margin-top:2px}'
            .'.header .doc .status{display:inline-block;margin-top:5px;padding:3px 10px;border:1px solid #c53030;color:#c53030;background:#fff5f5;font-size:8px;font-weight:bold}'
            .'.status-band{margin:10px 0 0 0;padding:5px 8px;border:1px solid #d9a3a3;background:#fbe9e9;font-size:9px;font-weight:bold;text-align:'.$align.'}'
            .'.info{margin-top:12px}.info .party-cell,.info .meta-cell{vertical-align:top;padding:10px;border:1px solid #d8dee9;background:#f8fafc}'
            .'.party-label{font-size:8px;color:#718096;margin-bottom:3px}.party-name{font-size:13px;font-weight:bold;color:#1f3a5f}'
            .'.party-details{font-size:8px;color:#4a5568;margin-top:3px}'
            .'.meta th{text-align:'.$align.';font-weight:normal;color:#4a5568;padding:3px 0;white-space:nowrap}'
            .'.meta td{text-align:'.($rtl ? 'left' : 'right').';padding:3px 0 3px 6px;font-weight:bold}'
            .'table.amount-box{margin-top:16px}table.amount-box th,table.amount-box td{padding:9px 12px;border:1px solid #1f3a5f}'
            .'table.amount-box th{background:#eef2f7;color:#1f3a5f;text-align:'.$align.';width:45%}'
            .'table.amount-box td{background:#1f3a5f;color:#ffffff;font-size:15px;font-weight:bold;text-align:center}'
            .'.section-label{margin-top:16px;font-size:9px;font-weight:bold;color:#1f3a5f;text-align:'.$align.'}'
            .'table.applied{margin-top:5px}table.applied td{border:1px solid #d8dee9;padding:6px 8px;text-align:'.$align.'}table.applied td.num{text-align:right;white-space:nowrap;width:30%}'
            .'.signoff{margin-top:46px;font-size:9px;page-break-inside:avoid}.signoff td{padding:0 14px;text-align:'.$align.';color:#4a5568;width:50%}'
            .'.signoff .line{border-bottom:1px solid #94a3b8;height:34px}'
            .'.footer{margin-top:24px;padding-top:6px;border-top:1px solid #d8dee9;font-size:7px;color:#718096;text-align:center}';

        return '<!doctype html><html lang="'.$lang.'" dir="'.($rtl ? 'rtl' : 'ltr').'"><head><meta charset="UTF-8"><style>'.$style.'</style></head><body>'
            .$header.$band.$info.$amount.$appliedTable.'<table class="signoff"><tr>'.$signHtml.'</tr></table>'.$footer.'</body></html>';
    }

    /** The receipt's worth in the base currency, from its stored rate (string maths on minor units; rounded half up). */
    private function baseEquivalent(Payment $payment): string
    {
        $base = (int) round(((int) $payment->amount_minor) * (float) $payment->fx_rate);

        return $this->groupDigits((new Money($base, $this->baseCurrency()))->toDecimal());
    }
}
