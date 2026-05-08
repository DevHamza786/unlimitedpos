@extends('layouts.guest')
@section('title', $title)
@section('content')

<style>
/* Full-page print (Ctrl+P): hide chrome and avoid a tall empty sheet */
@media print {
    @page {
        size: auto;
        margin: 8mm;
    }
    html {
        height: fit-content !important;
        min-height: 0 !important;
    }
    html,
    body {
        width: 100% !important;
        height: auto !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }
    .guest-invoice-slip .no-print,
    .guest-invoice-slip .spacer {
        display: none !important;
        height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .guest-invoice-slip .container {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .guest-invoice-slip .row {
        margin-left: 0 !important;
        margin-right: 0 !important;
    }
    .guest-invoice-slip .col-md-8,
    .guest-invoice-slip .col-md-offset-2,
    .guest-invoice-slip .col-sm-12,
    .guest-invoice-slip .col-md-12 {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        float: none !important;
    }
}
</style>

<div class="guest-invoice-slip">
<div class="container">
    <div class="spacer"></div>
    <div class="row">
        <div class="col-md-12 text-right mb-12" >
            @if(!empty($payment_link))
                <a href="{{$payment_link}}" class="btn btn-info no-print" style="margin-right: 20px;"><i class="fas fa-money-check-alt" title="@lang('lang_v1.pay')"></i> @lang('lang_v1.pay')
                </a>
            @endif
            <button type="button" class="btn btn-primary no-print btn-sm" id="print_invoice" 
                 aria-label="Print"><i class="fas fa-print"></i> @lang( 'messages.print' )
            </button>
            @auth
                <a href="{{action([\App\Http\Controllers\SellController::class, 'index'])}}" class="btn btn-success no-print btn-sm" ><i class="fas fa-backward"></i>
                </a>
            @endauth
        </div>
    </div>
    <div class="row">
        <div class="col-md-8 col-md-offset-2 col-sm-12" style="border: 1px solid #ccc;">
            <div class="spacer"></div>
            <div id="invoice_content">
                {!! $receipt['html_content'] !!}
            </div>
            <div class="spacer"></div>
        </div>
    </div>
    <div class="spacer"></div>
</div>
</div>
@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function(){
        var invoicePrintCss = "{{ asset('css/invoice-print.css') }}?v={{ $asset_v }}";
        $(document).on('click', '#print_invoice', function(){
            $('#invoice_content').printThis({
                importCSS: true,
                importStyle: true,
                loadCSS: invoicePrintCss,
                printDelay: 500,
                copyTagClasses: false,
                copyTagStyles: false
            });
        });
    });
    @if(!empty(request()->input('print_on_load')))
        $(window).on('load', function(){
            $('#invoice_content').printThis({
                importCSS: true,
                importStyle: true,
                loadCSS: "{{ asset('css/invoice-print.css') }}?v={{ $asset_v }}",
                printDelay: 500,
                copyTagClasses: false,
                copyTagStyles: false
            });
        });
    @endif
</script>
@endsection