<?php

namespace App\Http\Controllers;

use App\Business;
use App\Contact;
use App\NotificationTemplate;
use App\Notifications\CustomerNotification;
use App\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class VoucherController extends Controller
{
    public function issueModal($contact_id)
    {
        if (! auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $contact = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->findOrFail($contact_id);

        return view('contact.partials.issue_voucher_modal', compact('contact'));
    }

    public function listModal($contact_id)
    {
        if (! auth()->user()->can('customer.view') && ! auth()->user()->can('customer.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $contact = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->findOrFail($contact_id);

        $vouchers = Voucher::where('business_id', $business_id)
            ->where('contact_id', $contact->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('contact.partials.vouchers_list_modal', compact('contact', 'vouchers'));
    }

    public function store(Request $request)
    {
        if (! auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $validated = $request->validate([
            'contact_id' => 'required|integer',
            'discount_percent' => 'required|numeric|min:0.01|max:100',
            'expires_at' => 'nullable|date',
            'min_purchase_amount' => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'issued_via' => 'nullable|string|in:website,boss,manual',
            'send_email' => 'nullable|boolean',
            'email_to' => 'nullable|email',
            'voucher_note' => 'nullable|string|max:500',
        ]);

        $contact = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->findOrFail($validated['contact_id']);

        $voucher = null;

        DB::beginTransaction();
        try {
            $voucher = Voucher::create([
                'business_id' => $business_id,
                'contact_id' => $contact->id,
                'code' => $this->generateUniqueCode(),
                'discount_percent' => $validated['discount_percent'],
                'min_purchase_amount' => $validated['min_purchase_amount'] ?? null,
                'max_discount_amount' => $validated['max_discount_amount'] ?? null,
                'status' => 'active',
                'expires_at' => $validated['expires_at'] ?? null,
                'issued_by' => auth()->user()->id,
                'issued_via' => $validated['issued_via'] ?? 'manual',
            ]);

            if (! empty($validated['send_email'])) {
                $to = $validated['email_to'] ?? $contact->email;
                if (! empty($to)) {
                    $this->sendVoucherEmail($business_id, $contact, $voucher, $to, $validated['voucher_note'] ?? null);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            return [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return [
            'success' => true,
            'msg' => __('lang_v1.voucher_issued'),
            'data' => [
                'voucher_id' => $voucher->id,
                'code' => $voucher->code,
            ],
        ];
    }

    public function resend($voucher_id)
    {
        if (! auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $voucher = Voucher::where('business_id', $business_id)->findOrFail($voucher_id);
        $contact = Contact::where('business_id', $business_id)->findOrFail($voucher->contact_id);

        if (empty($contact->email)) {
            return ['success' => false, 'msg' => __('lang_v1.email') . ' ' . __('messages.not_found')];
        }

        $this->sendVoucherEmail($business_id, $contact, $voucher, $contact->email, null);

        return ['success' => true, 'msg' => __('lang_v1.email_sent')];
    }

    public function cancel($voucher_id)
    {
        if (! auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $voucher = Voucher::where('business_id', $business_id)->findOrFail($voucher_id);

        if ($voucher->status === 'redeemed') {
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        $voucher->status = 'cancelled';
        $voucher->save();

        return ['success' => true, 'msg' => __('messages.success')];
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = 'VC-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4));
        } while (Voucher::where('code', $code)->exists());

        return $code;
    }

    private function sendVoucherEmail($business_id, Contact $contact, Voucher $voucher, string $to_email, ?string $voucher_note): void
    {
        $business = Business::findOrFail($business_id);
        $template = NotificationTemplate::getTemplate($business_id, 'voucher_issued');

        $expires = ! empty($voucher->expires_at) ? $voucher->expires_at->format('Y-m-d') : __('lang_v1.none');
        $business_logo = ! empty($business->logo) ? '<img src="'.url('uploads/business_logos/'.$business->logo).'" alt="Business Logo" >' : '';

        $email_body = $template['email_body'] ?? '';
        $subject = $template['subject'] ?? '';

        $replacements = [
            '{business_name}' => $business->name,
            '{business_logo}' => $business_logo,
            '{contact_name}' => $contact->name,
            '{voucher_code}' => $voucher->code,
            '{voucher_discount_percent}' => (float) $voucher->discount_percent,
            '{voucher_expiry}' => $expires,
            '{voucher_note}' => ! empty($voucher_note) ? e($voucher_note) : '',
        ];

        $email_body = strtr($email_body, $replacements);
        $subject = strtr($subject, $replacements);

        $data = [
            'subject' => $subject,
            'email_body' => $email_body,
            'to_email' => $to_email,
        ];

        Notification::route('mail', $to_email)->notify(new CustomerNotification($data));

        $voucher->sent_to_email = $to_email;
        $voucher->sent_at = now();
        $voucher->save();
    }
}

