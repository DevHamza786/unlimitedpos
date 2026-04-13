<div class="pos-tab-content">
    <h4>@lang('business.woocommerce_integration')</h4>
    <p class="help-block">@lang('business.woocommerce_credentials_note')</p>
    <p class="help-block text-info"><strong>@lang('business.woocommerce_push_hint')</strong></p>
    <div class="row">
        <div class="col-sm-12">
            <div class="form-group">
                <div class="checkbox">
                    <label>
                        {!! Form::hidden('woocommerce_enabled', 0) !!}
                        {!! Form::checkbox('woocommerce_enabled', 1, ! empty($business->woocommerce_enabled), ['class' => 'input-icheck']); !!}
                        @lang('business.woocommerce_enable')
                    </label>
                </div>
            </div>
        </div>
        <div class="col-sm-12">
            <div class="form-group">
                {!! Form::label('woocommerce_store_url', __('business.woocommerce_store_url') . ':') !!}
                @show_tooltip(__('business.woocommerce_store_url_help'))
                {!! Form::text('woocommerce_store_url', $business->woocommerce_store_url, ['class' => 'form-control', 'placeholder' => 'https://shop.example.com', 'id' => 'woocommerce_store_url']); !!}
            </div>
        </div>
        <div class="col-sm-6">
            <div class="form-group">
                {!! Form::label('woocommerce_consumer_key', __('business.woocommerce_consumer_key') . ':') !!}
                {!! Form::text('woocommerce_consumer_key', null, ['class' => 'form-control', 'placeholder' => __('business.woocommerce_leave_empty_to_keep'), 'id' => 'woocommerce_consumer_key', 'autocomplete' => 'off']); !!}
            </div>
        </div>
        <div class="col-sm-6">
            <div class="form-group">
                {!! Form::label('woocommerce_consumer_secret', __('business.woocommerce_consumer_secret') . ':') !!}
                {!! Form::password('woocommerce_consumer_secret', ['class' => 'form-control', 'placeholder' => __('business.woocommerce_leave_empty_to_keep'), 'id' => 'woocommerce_consumer_secret', 'autocomplete' => 'off']); !!}
            </div>
        </div>
        <div class="col-sm-12">
            <button type="button" class="btn btn-info" id="test_woocommerce_btn">@lang('business.woocommerce_test_connection')</button>
        </div>
        @if ($business->hasWooCommerceApiCredentials())
        <div class="col-sm-12"><hr></div>
        <div class="col-sm-12">
            <h5>@lang('business.woocommerce_orders_to_pos')</h5>
            <p class="help-block">@lang('business.woocommerce_orders_to_pos_help')</p>
            <div class="form-group">
                <div class="checkbox">
                    <label>
                        {!! Form::hidden('woocommerce_import_orders_enabled', 0) !!}
                        {!! Form::checkbox('woocommerce_import_orders_enabled', 1, ! empty($business->woocommerce_import_orders_enabled), ['class' => 'input-icheck']); !!}
                        @lang('business.woocommerce_import_orders_enable')
                    </label>
                </div>
            </div>
            <div class="form-group">
                {!! Form::label('woocommerce_default_location_id', __('business.woocommerce_default_location') . ':') !!}
                {!! Form::select('woocommerce_default_location_id', $woocommerce_locations, $business->woocommerce_default_location_id, ['class' => 'form-control select2', 'placeholder' => __('messages.please_select')]); !!}
                <p class="help-block">@lang('business.woocommerce_default_location_help')</p>
            </div>
            @if (empty($business->woocommerce_webhook_token) && ! empty($business->woocommerce_import_orders_enabled))
            <p class="text-warning">@lang('business.woocommerce_save_to_generate_webhook_url')</p>
            @endif
            @if (! empty($business->woocommerce_webhook_token))
            @php
                $whBase = parse_url((string) config('app.url'), PHP_URL_HOST);
                $whBase = $whBase ? strtolower($whBase) : '';
                $woocommerceWebhookHostIsLocal = $whBase === 'localhost'
                    || $whBase === '127.0.0.1'
                    || $whBase === '::1'
                    || str_ends_with($whBase, '.local')
                    || str_ends_with($whBase, '.test');
            @endphp
            @if ($woocommerceWebhookHostIsLocal)
            <div class="alert alert-danger">
                <strong>@lang('business.woocommerce_webhook_localhost_title')</strong>
                <p>@lang('business.woocommerce_webhook_localhost_body')</p>
                <p><code>APP_URL</code> @lang('business.woocommerce_webhook_set_app_url')</p>
            </div>
            @endif
            <div class="form-group">
                <label>@lang('business.woocommerce_webhook_url')</label>
                <div class="input-group">
                    <input type="text" class="form-control" readonly value="{{ url('/webhook/woocommerce/'.$business->woocommerce_webhook_token) }}" id="woocommerce_webhook_url_field">
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-default" title="@lang('lang_v1.copy')" onclick="var e=document.getElementById('woocommerce_webhook_url_field'); e.select(); document.execCommand('copy');"><i class="fa fa-copy"></i></button>
                    </span>
                </div>
                <p class="help-block">@lang('business.woocommerce_webhook_url_help')</p>
            </div>
            <div class="form-group">
                <div class="checkbox">
                    <label>
                        {!! Form::hidden('woocommerce_regenerate_webhook_token', 0) !!}
                        {!! Form::checkbox('woocommerce_regenerate_webhook_token', 1, false, ['class' => 'input-icheck']); !!}
                        @lang('business.woocommerce_regenerate_webhook_token')
                    </label>
                </div>
            </div>
            @endif
            <div class="form-group">
                {!! Form::label('woocommerce_webhook_secret', __('business.woocommerce_webhook_secret') . ':') !!}
                {!! Form::password('woocommerce_webhook_secret', ['class' => 'form-control', 'placeholder' => __('business.woocommerce_webhook_secret_placeholder'), 'autocomplete' => 'off']); !!}
                <p class="help-block">@lang('business.woocommerce_webhook_secret_help')</p>
                <p class="help-block text-warning">@lang('business.woocommerce_webhook_secret_401_help')</p>
                <div class="checkbox">
                    <label>
                        {!! Form::hidden('woocommerce_webhook_secret_remove', 0) !!}
                        {!! Form::checkbox('woocommerce_webhook_secret_remove', 1, false, ['class' => 'input-icheck']); !!}
                        @lang('business.woocommerce_webhook_secret_remove')
                    </label>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
