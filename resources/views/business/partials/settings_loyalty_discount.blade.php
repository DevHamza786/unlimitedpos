<div class="pos-tab-content">
<div class="row well">
    <div class="col-sm-12">
        <h4>@lang('lang_v1.auto_loyalty_discount_settings')</h4>
        <p class="help-block"><i>@lang('lang_v1.auto_loyalty_discount_help')</i></p>
    </div>
    <div class="col-sm-4">
        <div class="form-group">
            <div class="checkbox">
                <label>
                {!! Form::checkbox('enable_auto_loyalty_discount', 1, !empty($business->enable_auto_loyalty_discount),
                [ 'class' => 'input-icheck', 'id' => 'enable_auto_loyalty_discount']); !!} {{ __( 'lang_v1.enable_auto_loyalty_discount' ) }}
                </label>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="form-group">
            {!! Form::label('auto_discount_min_sales', __('lang_v1.auto_discount_min_sales') . ':') !!}
            {!! Form::text('auto_discount_min_sales', @num_format($business->auto_discount_min_sales), ['class' => 'form-control input_number','placeholder' => __('lang_v1.auto_discount_min_sales')]); !!}
        </div>
    </div>
    <div class="col-sm-4">
        <div class="form-group">
            {!! Form::label('auto_discount_percent', __('lang_v1.auto_discount_percent') . ':') !!}
            {!! Form::text('auto_discount_percent', @num_format($business->auto_discount_percent), ['class' => 'form-control input_number','placeholder' => __('lang_v1.auto_discount_percent')]); !!}
        </div>
    </div>
</div>
</div>
