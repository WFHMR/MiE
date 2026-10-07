@extends( 'layout.dashboard' )

@section('layout.dashboard.header')
    <style>
        .product-usage-guide{margin:20px 0 28px;padding:18px;min-width:0;flex-shrink:0;line-height:1.7;overflow-wrap:anywhere}
        .product-usage-guide h2{font-size:20px;font-weight:700;margin:0 0 8px}
        .product-usage-guide p{margin:8px 0}.product-usage-guide details{border-top:1px solid currentColor;margin-top:12px;padding-top:4px}
        .product-usage-guide summary{cursor:pointer;font-weight:700;padding:12px 0;min-height:44px}.product-usage-guide summary:focus-visible{outline:2px solid currentColor;outline-offset:3px}
        .product-usage-guide ul,.product-usage-guide ol{padding-left:24px;margin:8px 0;list-style:disc}.product-usage-guide ol{list-style:decimal}.product-usage-guide li{margin:7px 0}
        @media(max-width:640px){.product-usage-guide{padding:12px}.product-usage-guide h2{font-size:18px}}
        @media print{.product-usage-guide{display:none}}
    </style>
@endsection

@section( 'layout.dashboard.body' )
<div class="h-full flex-auto flex flex-col">
    @include( Hook::filter( 'ns-dashboard-header-file', '../common/dashboard-header' ) )
    <div class="px-4 flex-auto flex flex-col" id="dashboard-content">
        @include( 'common.dashboard.title' )
        <ns-manage-products
            return-url="{{ $returnUrl ?? '#' }}"
            submit-method="{{ $submitMethod ?? 'POST' }}"
            submit-url="{{ $submitUrl }}"
            units-url="{{ $unitsUrl }}"
            unit-src="{{ $unitSrc }}"
            unit-submit-url="{{ $unitSubmitUrl }}"
            src="{{ $src }}">
            <template v-slot:title>{{ $mainFieldLabel ?? __( 'mainFieldLabel not defined' ) }}</template>
            <template v-slot:save>{{ $saveButton ?? __( 'Save' ) }}</template>
            <template v-slot:error-required>{{ $fieldRequired ?? __( 'This field is required' ) }}</template>
            <template v-slot:error-invalid-form>{{ $formNotValid ?? __( 'The form is not valid. Please check it and try again' ) }}</template>
        </ns-manage-products>
        @include('pages.dashboard.products.usage-guide')
    </div>
</div>
@endsection
