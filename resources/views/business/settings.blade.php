@extends('layouts.app')
@section('title', __('business.business_settings'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang('business.business_settings')</h1>
    <br>
    @include('layouts.partials.search_settings')
</section>

<!-- Main content -->
<section class="content">
{!! Form::open(['url' => action([\App\Http\Controllers\BusinessController::class, 'postBusinessSettings']), 'method' => 'post', 'id' => 'bussiness_edit_form',
           'files' => true ]) !!}
    <div class="row">
        <div class="col-xs-12">
       <!--  <pos-tab-container> -->
        <div class="col-xs-12 pos-tab-container">
            <div class="col-lg-2 col-md-2 col-sm-2 col-xs-2 pos-tab-menu">
                <div class="list-group">
                    <a href="#" class="list-group-item text-center active">@lang('business.business')</a>
                    <a href="#" class="list-group-item text-center">@lang('business.tax') @show_tooltip(__('tooltip.business_tax'))</a>
                    <a href="#" class="list-group-item text-center">@lang('business.product')</a>
                    <a href="#" class="list-group-item text-center">@lang('contact.contact')</a>
                    <a href="#" class="list-group-item text-center">@lang('business.sale')</a>
                    <a href="#" class="list-group-item text-center">@lang('sale.pos_sale')</a>
                    <a href="#" class="list-group-item text-center">@lang('purchase.purchases')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.payment')</a>
                    <a href="#" class="list-group-item text-center">@lang('business.dashboard')</a>
                    <a href="#" class="list-group-item text-center">@lang('business.system')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.prefixes')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.email_settings')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.sms_settings')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.reward_point_settings')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.modules')</a>
                    <a href="#" class="list-group-item text-center">@lang('business.woocommerce')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.custom_labels')</a>
                </div>
            </div>
            <div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 pos-tab">
                <!-- tab 1 start -->
                @include('business.partials.settings_business')
                <!-- tab 1 end -->
                <!-- tab 2 start -->
                @include('business.partials.settings_tax')
                <!-- tab 2 end -->
                <!-- tab 3 start -->
                @include('business.partials.settings_product')

                @include('business.partials.settings_contact')
                <!-- tab 3 end -->
                <!-- tab 4 start -->
                @include('business.partials.settings_sales')
                @include('business.partials.settings_pos')
                <!-- tab 4 end -->
                <!-- tab 5 start -->
                @include('business.partials.settings_purchase')

                @include('business.partials.settings_payment')
                <!-- tab 5 end -->
                <!-- tab 6 start -->
                @include('business.partials.settings_dashboard')
                <!-- tab 6 end -->
                <!-- tab 7 start -->
                @include('business.partials.settings_system')
                <!-- tab 7 end -->
                <!-- tab 8 start -->
                @include('business.partials.settings_prefixes')
                <!-- tab 8 end -->
                <!-- tab 9 start -->
                @include('business.partials.settings_email')
                <!-- tab 9 end -->
                <!-- tab 10 start -->
                @include('business.partials.settings_sms')
                <!-- tab 10 end -->
                <!-- tab 11 start -->
                @include('business.partials.settings_reward_point')
                <!-- tab 11 end -->
                <!-- tab 12 start -->
                @include('business.partials.settings_modules')
                <!-- tab 12 end -->
                @include('business.partials.settings_woocommerce')
                @include('business.partials.woocommerce_import_modal')
                @include('business.partials.settings_custom_labels')
            </div>
        </div>
        <!--  </pos-tab-container> -->
        </div>
    </div>

    <div class="row">
        <div class="col-sm-12 text-center">
            <button class="btn btn-danger btn-big" type="submit">@lang('business.update_settings')</button>
        </div>
    </div>
{!! Form::close() !!}
</section>
<!-- /.content -->
@stop
@section('javascript')
<script type="text/javascript">
    __page_leave_confirmation('#bussiness_edit_form');
    $(document).on('ifToggled', '#use_superadmin_settings', function() {
        if ($('#use_superadmin_settings').is(':checked')) {
            $('#toggle_visibility').addClass('hide');
            $('.test_email_btn').addClass('hide');
        } else {
            $('#toggle_visibility').removeClass('hide');
            $('.test_email_btn').removeClass('hide');
        }
    });

    $(document).ready(function(){

    
        $('#test_email_btn').click( function() {
            var data = {
                mail_driver: $('#mail_driver').val(),
                mail_host: $('#mail_host').val(),
                mail_port: $('#mail_port').val(),
                mail_username: $('#mail_username').val(),
                mail_password: $('#mail_password').val(),
                mail_encryption: $('#mail_encryption').val(),
                mail_from_address: $('#mail_from_address').val(),
                mail_from_name: $('#mail_from_name').val(),
            };
            $.ajax({
                method: 'post',
                data: data,
                url: "{{ action([\App\Http\Controllers\BusinessController::class, 'testEmailConfiguration']) }}",
                dataType: 'json',
                success: function(result) {
                    if (result.success == true) {
                        swal({
                            text: result.msg,
                            icon: 'success'
                        });
                    } else {
                        swal({
                            text: result.msg,
                            icon: 'error'
                        });
                    }
                },
            });
        });

        $('#test_sms_btn').click( function() {
            var test_number = $('#test_number').val();
            if (test_number.trim() == '') {
                toastr.error('{{__("lang_v1.test_number_is_required")}}');
                $('#test_number').focus();

                return false;
            }

            var data = {
                url: $('#sms_settings_url').val(),
                send_to_param_name: $('#send_to_param_name').val(),
                msg_param_name: $('#msg_param_name').val(),
                request_method: $('#request_method').val(),
                param_1: $('#sms_settings_param_key1').val(),
                param_2: $('#sms_settings_param_key2').val(),
                param_3: $('#sms_settings_param_key3').val(),
                param_4: $('#sms_settings_param_key4').val(),
                param_5: $('#sms_settings_param_key5').val(),
                param_6: $('#sms_settings_param_key6').val(),
                param_7: $('#sms_settings_param_key7').val(),
                param_8: $('#sms_settings_param_key8').val(),
                param_9: $('#sms_settings_param_key9').val(),
                param_10: $('#sms_settings_param_key10').val(),

                param_val_1: $('#sms_settings_param_val1').val(),
                param_val_2: $('#sms_settings_param_val2').val(),
                param_val_3: $('#sms_settings_param_val3').val(),
                param_val_4: $('#sms_settings_param_val4').val(),
                param_val_5: $('#sms_settings_param_val5').val(),
                param_val_6: $('#sms_settings_param_val6').val(),
                param_val_7: $('#sms_settings_param_val7').val(),
                param_val_8: $('#sms_settings_param_val8').val(),
                param_val_9: $('#sms_settings_param_val9').val(),
                param_val_10: $('#sms_settings_param_val10').val(),
                test_number: test_number
            };

            $.ajax({
                method: 'post',
                data: data,
                url: "{{ action([\App\Http\Controllers\BusinessController::class, 'testSmsConfiguration']) }}",
                dataType: 'json',
                success: function(result) {
                    if (result.success == true) {
                        swal({
                            text: result.msg,
                            icon: 'success'
                        });
                    } else {
                        swal({
                            text: result.msg,
                            icon: 'error'
                        });
                    }
                },
            });

        });

        $('#test_woocommerce_btn').click(function() {
            var data = {
                _token: '{{ csrf_token() }}',
                woocommerce_store_url: $('#woocommerce_store_url').val(),
                woocommerce_consumer_key: $('#woocommerce_consumer_key').val(),
                woocommerce_consumer_secret: $('#woocommerce_consumer_secret').val(),
            };
            $.ajax({
                method: 'post',
                data: data,
                url: "{{ action([\App\Http\Controllers\BusinessController::class, 'postTestWooCommerce']) }}",
                dataType: 'json',
                success: function(result) {
                    if (result.success == 1 || result.success === true) {
                        swal({ text: result.msg, icon: 'success' });
                    } else {
                        swal({ text: result.msg, icon: 'error' });
                    }
                },
                error: function(xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : xhr.statusText;
                    swal({ text: msg, icon: 'error' });
                }
            });
        });

        // WooCommerce product import
        $('#import_woocommerce_products_btn').click(function() {
            $('#woocommerce_import_modal').modal('show');
            loadWooCommerceProducts();
        });

        function loadWooCommerceProducts(page = 1) {
            $('#woo_import_loading').removeClass('hide');
            $('#woo_import_error').addClass('hide');
            $('#woo_import_products_list').addClass('hide');
            $('#woo_import_result').addClass('hide');

            $.ajax({
                method: 'get',
                url: '/woocommerce/products',
                data: { page: page, per_page: 50 },
                dataType: 'json',
                success: function(result) {
                    $('#woo_import_loading').addClass('hide');
                    if (result.success) {
                        var products = result.products;
                        var tbody = $('#woo_products_tbody');
                        tbody.empty();

                        $.each(products, function(index, product) {
                            var type = product.type === 'variable' ? 'Variable' : 'Simple';
                            var price = product.regular_price || '0';
                            var sku = product.sku || '-';
                            var name = product.name || 'Untitled';

                            tbody.append(
                                '<tr>' +
                                '<td><input type="checkbox" class="woo_product_check" value="' + product.id + '"></td>' +
                                '<td>' + name + '</td>' +
                                '<td>' + sku + '</td>' +
                                '<td>' + price + '</td>' +
                                '<td>' + type + '</td>' +
                                '</tr>'
                            );
                        });

                        $('#woo_import_products_list').removeClass('hide');
                        $('#woo_select_all').prop('checked', false);
                        updateImportButton();
                    } else {
                        $('#woo_import_error').text(result.message).removeClass('hide');
                    }
                },
                error: function(xhr) {
                    $('#woo_import_loading').addClass('hide');
                    var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : xhr.statusText;
                    $('#woo_import_error').text(msg).removeClass('hide');
                }
            });
        }

        $('#woo_select_all').change(function() {
            $('.woo_product_check').prop('checked', $(this).prop('checked'));
            updateImportButton();
        });

        $(document).on('change', '.woo_product_check', function() {
            updateImportButton();
        });

        function updateImportButton() {
            var count = $('.woo_product_check:checked').length;
            $('#woo_import_selected_btn').prop('disabled', count === 0);
            $('#woo_import_selected_btn').html(
                '<i class="fa fa-download"></i> ' +
                $('#woo_import_selected_btn').data('label') + ' (' + count + ')'
            );
        }

        $('#woo_import_selected_btn').click(function() {
            var selectedIds = [];
            $('.woo_product_check:checked').each(function() {
                selectedIds.push($(this).val());
            });

            if (selectedIds.length === 0) {
                return;
            }

            $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Importing...');

            $.ajax({
                method: 'post',
                url: '/woocommerce/import-products',
                data: {
                    _token: '{{ csrf_token() }}',
                    product_ids: selectedIds
                },
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        swal({ text: result.message, icon: 'success' });
                    } else {
                        swal({ text: result.message, icon: 'error' });
                    }
                    $('#woocommerce_import_modal').modal('hide');
                },
                error: function(xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : xhr.statusText;
                    swal({ text: msg, icon: 'error' });
                }
            });
        });

        $('select.custom_labels_products').change(function(){
            value = $(this).val();
            textarea = $(this).parents('div.custom_label_product_div').find('div.custom_label_product_dropdown');
            if(value == 'dropdown'){
                textarea.removeClass('hide');
            } else{
                textarea.addClass('hide');
            }
        })
    });
</script>
@endsection