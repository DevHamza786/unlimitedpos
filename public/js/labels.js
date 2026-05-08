$(document).ready(function() {
    function apply_default_label_qty_to_all_rows() {
        var qty = parseInt($('#default_label_qty').val(), 10);
        if (isNaN(qty) || qty <= 0) {
            return;
        }
        $('table#product_table tbody').find('input[type="number"][name$="[quantity]"]').val(qty);
    }

    $('table#product_table tbody').find('.label-date-picker').each( function(){
        $(this).datepicker({
            autoclose: true
        });
    });
    //Add products
    if ($('#search_product_for_label').length > 0) {
        // Use Select2 (more reliable than jquery-ui autocomplete across pages)
        $('#search_product_for_label')
            .select2({
                placeholder: $('#search_product_for_label').data('placeholder') || '',
                closeOnSelect: false,
                ajax: {
                    url: '/purchases/get_products',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            term: params.term,
                            check_enable_stock: false,
                            show_all: true,
                            brand_id: $('#brand_id_for_label').val(),
                        };
                    },
                    processResults: function(data) {
                        // Endpoint already returns [{id, text, product_id, variation_id}, ...]
                        return { results: data };
                    },
                    cache: true,
                },
                minimumInputLength: 0,
                width: 'resolve',
            })
            .on('select2:select', function(e) {
                var item = e.params.data;
                if (item && item.product_id) {
                    get_label_product_row(item.product_id, item.variation_id);
                }

                // Remove selected item from selection so user can keep picking quickly.
                var $el = $('#search_product_for_label');
                var vals = $el.val() || [];
                var next = vals.filter(function(v) {
                    return String(v) !== String(item.id);
                });
                $el.val(next).trigger('change.select2');
                $el.select2('open');
            });
    }

    $(document).on('change', '#brand_id_for_label', function() {
        // Clear current selection and force reload with brand filter.
        $('#search_product_for_label').val(null).trigger('change');
    });

    $(document).on('input change', '#default_label_qty', function() {
        apply_default_label_qty_to_all_rows();
    });

    $('input#is_show_price').change(function() {
        if ($(this).is(':checked')) {
            $('div#price_type_div').show();
        } else {
            $('div#price_type_div').hide();
        }
    });

    $('button#labels_preview').click(function() {
        if ($('form#preview_setting_form table#product_table tbody tr').length > 0) {
            var url = base_path + '/labels/preview?' + $('form#preview_setting_form').serialize();

            window.open(url, 'newwindow');

            // $.ajax({
            //     method: 'get',
            //     url: '/labels/preview',
            //     dataType: 'json',
            //     data: $('form#preview_setting_form').serialize(),
            //     success: function(result) {
            //         if (result.success) {
            //             $('div.display_label_div').removeClass('hide');
            //             $('div#preview_box').html(result.html);
            //             __currency_convert_recursively($('div#preview_box'));
            //         } else {
            //             toastr.error(result.msg);
            //         }
            //     },
            // });
        } else {
            swal(LANG.label_no_product_error).then(value => {
                $('#search_product_for_label').focus();
            });
        }
    });

    $(document).on('click', 'button#print_label', function() {
        window.print();
    });
});

function get_label_product_row(product_id, variation_id) {
    if (product_id) {
        var row_count = $('table#product_table tbody tr').length;
        $.ajax({
            method: 'GET',
            url: '/labels/add-product-row',
            dataType: 'html',
            data: { product_id: product_id, row_count: row_count, variation_id: variation_id },
            success: function(result) {
                $('table#product_table tbody').append(result);

                $('table#product_table tbody').find('.label-date-picker').each( function(){
                    $(this).datepicker({
                        autoclose: true
                    });
                });

                // Apply default qty to newly added rows too.
                if ($('#default_label_qty').length) {
                    var qty = parseInt($('#default_label_qty').val(), 10);
                    if (!isNaN(qty) && qty > 0) {
                        $('table#product_table tbody')
                            .find('input[type="number"][name$="[quantity]"]')
                            .last()
                            .val(qty);
                    }
                }
            },
        });
    }
}
