<div class="pos-tab-content">
    <h4>@lang('business.square_integration')</h4>
    <p class="help-block">@lang('business.square_integration_help')</p>
    <div class="row">
        <div class="col-sm-12">
            <div class="form-group">
                <div class="checkbox">
                    <label>
                        {!! Form::hidden('square_enabled', 0) !!}
                        {!! Form::checkbox('square_enabled', 1, ! empty($business->square_enabled), ['class' => 'input-icheck', 'id' => 'square_enabled']); !!}
                        @lang('business.square_enable')
                    </label>
                </div>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="form-group">
                {!! Form::label('square_environment', __('business.square_environment') . ':') !!}
                {!! Form::select('square_environment', [
                    'production' => __('business.square_environment_production'),
                    'sandbox' => __('business.square_environment_sandbox'),
                ], $business->square_environment ?? 'production', ['class' => 'form-control', 'id' => 'square_environment']); !!}
            </div>
        </div>
        <div class="col-sm-6">
            <div class="form-group">
                {!! Form::label('square_location_id', __('business.square_location_id') . ':') !!}
                {!! Form::text('square_location_id', $business->square_location_id, ['class' => 'form-control', 'id' => 'square_location_id', 'autocomplete' => 'off']); !!}
                <p class="help-block">@lang('business.square_location_id_help')</p>
            </div>
        </div>
        <div class="col-sm-12">
            <div class="form-group">
                {!! Form::label('square_access_token', __('business.square_access_token') . ':') !!}
                {!! Form::password('square_access_token', ['class' => 'form-control', 'placeholder' => __('business.woocommerce_leave_empty_to_keep'), 'id' => 'square_access_token', 'autocomplete' => 'off']); !!}
                <p class="help-block">@lang('business.square_access_token_help')</p>
                <div class="checkbox">
                    <label>
                        {!! Form::hidden('square_remove_token', 0) !!}
                        {!! Form::checkbox('square_remove_token', 1, false, ['class' => 'input-icheck']); !!}
                        @lang('business.square_remove_token')
                    </label>
                </div>
            </div>
        </div>
        <div class="col-sm-12">
            <button type="button" class="btn btn-info" id="test_square_btn">@lang('business.square_test_connection')</button>
            @if ($business->hasSquareApiCredentials())
                <button type="button" class="btn btn-success" id="square_sync_payments_btn">@lang('business.square_sync_payments')</button>
                <span class="help-block" style="display:inline;margin-left:8px;">@lang('business.square_sync_payments_help')</span>
                @if (! empty($business->square_last_synced_at))
                    <p class="text-muted">@lang('lang_v1.last_updated'): {{ @format_datetime($business->square_last_synced_at) }}</p>
                @endif
            @endif
        </div>
    </div>
</div>
