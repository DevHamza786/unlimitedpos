<div class="modal" id="woocommerce_import_modal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" onclick="closeWooImportModal()">&times;</button>
                <h4 class="modal-title">@lang('business.woocommerce_import_products')</h4>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-sm-12">
                        <div id="woo_import_loading" class="text-center">
                            <i class="fa fa-spinner fa-spin fa-3x"></i>
                            <p>@lang('business.woocommerce_fetching_products')</p>
                        </div>
                        <div id="woo_import_error" class="alert alert-danger hide"></div>
                        <div id="woo_import_products_list" class="hide">
                            <p class="help-block">@lang('business.woocommerce_select_products_to_import')</p>
                            <table class="table table-condensed">
                                <thead>
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" id="woo_select_all">
                                        </th>
                                        <th>@lang('product.product_name')</th>
                                        <th>@lang('product.sku')</th>
                                        <th>@lang('product.price')</th>
                                        <th>@lang('product.type')</th>
                                    </tr>
                                </thead>
                                <tbody id="woo_products_tbody">
                                </tbody>
                            </table>
                            <div class="text-center">
                                <button class="btn btn-success" id="woo_import_selected_btn" disabled data-label="@lang('business.woocommerce_import_selected')">
                                    <i class="fa fa-download"></i> @lang('business.woocommerce_import_selected')
                                </button>
                            </div>
                        </div>
                        <div id="woo_import_result" class="hide"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" onclick="closeWooImportModal()">@lang('messages.close')</button>
            </div>
        </div>
    </div>
</div>

<script>
function __wooImportCleanupModalArtifacts() {
    // Bootstrap sometimes leaves a stale backdrop which blocks the UI.
    $('.modal-backdrop').remove();
    $('body').removeClass('modal-open').css('padding-right', '');
}

function closeWooImportModal() {
    $('#woocommerce_import_modal').modal('hide');
    setTimeout(__wooImportCleanupModalArtifacts, 0);
}
</script>