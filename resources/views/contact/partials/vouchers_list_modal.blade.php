<div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                    aria-hidden="true">&times;</span></button>
            <h4 class="modal-title">
                @lang('lang_v1.vouchers') - {{$contact->name}}
            </h4>
        </div>
        <div class="modal-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>@lang('lang_v1.voucher_code')</th>
                            <th>@lang('lang_v1.discount_percent')</th>
                            <th>@lang('sale.status')</th>
                            <th>@lang('lang_v1.expiry_date')</th>
                            <th>@lang('lang_v1.sent_on')</th>
                            <th>@lang('lang_v1.voucher_send_to_email')</th>
                            <th>@lang('messages.action')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $voucher_list_reload_url = action([\App\Http\Controllers\VoucherController::class, 'listModal'], [$contact->id]);
                        @endphp
                        @forelse($vouchers as $v)
                            <tr>
                                <td><code>{{$v->code}}</code></td>
                                <td>{{$v->discount_percent}}%</td>
                                <td>{{$v->status}}</td>
                                <td>{{!empty($v->expires_at) ? @format_date($v->expires_at) : __('lang_v1.none')}}</td>
                                <td>{{!empty($v->sent_at) ? @format_date($v->sent_at) : '-'}}</td>
                                <td style="min-width: 200px;">
                                    @if(auth()->user()->can('customer.update'))
                                        @php
                                            $default_resend_email = $v->sent_to_email ?: $contact->email;
                                        @endphp
                                        <input type="email" class="form-control input-sm voucher_resend_email_input"
                                            value="{{ $default_resend_email }}"
                                            placeholder="@lang('business.email')"
                                            autocomplete="email">
                                        <label class="checkbox-inline" style="margin-top: 6px; font-weight: normal;">
                                            <input type="checkbox" class="voucher_update_contact_email_chk">
                                            @lang('lang_v1.voucher_update_contact_email')
                                        </label>
                                    @else
                                        {{ $v->sent_to_email ?: $contact->email ?: '—' }}
                                    @endif
                                </td>
                                <td>
                                    @if(auth()->user()->can('customer.update'))
                                        <button type="button"
                                            class="btn btn-xs btn-primary resend_voucher_email"
                                            data-href="{{action([\App\Http\Controllers\VoucherController::class, 'resend'], [$v->id])}}"
                                            data-reload-href="{{ $voucher_list_reload_url }}">
                                            @lang('lang_v1.resend')
                                        </button>
                                        @if($v->status === 'active')
                                            <button type="button"
                                                class="btn btn-xs btn-danger cancel_voucher"
                                                data-href="{{action([\App\Http\Controllers\VoucherController::class, 'cancel'], [$v->id])}}">
                                                @lang('messages.cancel')
                                            </button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center">@lang('lang_v1.no_records_found')</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">@lang('messages.close')</button>
        </div>
    </div>
</div>

