<div class="modal-dialog" role="document">
    <div class="modal-content">
        {!! Form::open(['url' => action([\App\Http\Controllers\VoucherController::class, 'store']), 'method' => 'post', 'id' => 'issue_voucher_form']) !!}
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                    aria-hidden="true">&times;</span></button>
            <h4 class="modal-title">
                @lang('lang_v1.voucher_issued') - {{$contact->name}}
            </h4>
        </div>
        <div class="modal-body">
            {!! Form::hidden('contact_id', $contact->id) !!}
            {!! Form::hidden('issued_via', 'manual') !!}

            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        {!! Form::label('discount_percent', __('lang_v1.discount_percent') . ':*') !!}
                        {!! Form::number('discount_percent', null, ['class' => 'form-control', 'required', 'min' => 0.01, 'max' => 100, 'step' => 0.01]) !!}
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        {!! Form::label('max_discount_amount', __('lang_v1.max_discount') . ' (' . __('lang_v1.optional') . '):') !!}
                        {!! Form::number('max_discount_amount', null, ['class' => 'form-control', 'min' => 0, 'step' => 0.01]) !!}
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        {!! Form::label('min_purchase_amount', __('lang_v1.minimum_sale') . ' (' . __('lang_v1.optional') . '):') !!}
                        {!! Form::number('min_purchase_amount', null, ['class' => 'form-control', 'min' => 0, 'step' => 0.01]) !!}
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        {!! Form::label('expires_at', __('lang_v1.expiry_date') . ' (' . __('lang_v1.optional') . '):') !!}
                        {!! Form::date('expires_at', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="form-group">
                        {!! Form::label('voucher_note', __('lang_v1.note') . ' (' . __('lang_v1.optional') . '):') !!}
                        {!! Form::text('voucher_note', null, ['class' => 'form-control', 'maxlength' => 500]) !!}
                    </div>
                </div>
            </div>

            <hr>

            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>
                            {!! Form::checkbox('send_email', 1, !empty($contact->email), ['class' => 'input-icheck', 'id' => 'send_voucher_email']) !!}
                            <strong>@lang('lang_v1.send') @lang('lang_v1.email')</strong>
                        </label>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="form-group">
                        {!! Form::label('email_to', __('lang_v1.email') . ':') !!}
                        {!! Form::email('email_to', $contact->email, ['class' => 'form-control', 'placeholder' => __('lang_v1.email')]) !!}
                    </div>
                </div>
            </div>

            <p class="help-block">
                @lang('sale.discount') @lang('lang_v1.calculated_on') @lang('sale.subtotal') + @lang('sale.tax')
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">@lang('messages.close')</button>
            <button type="submit" class="btn btn-primary">@lang('messages.save')</button>
        </div>
        {!! Form::close() !!}
    </div>
</div>

