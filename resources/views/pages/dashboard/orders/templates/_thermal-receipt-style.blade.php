<style>
    /* Scope thermal typography to the slip and its settings preview only. */
    .rgbf-thermal-receipt {
        box-sizing: border-box;
        font-family: Tahoma, Arial, sans-serif;
        font-size: 13px;
        font-weight: 700;
        line-height: 1.5;
        color: #000;
        background: #fff;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        overflow-wrap: anywhere;
    }

    .rgbf-thermal-receipt * {
        font-family: inherit;
        color: #000 !important;
    }

    .rgbf-thermal-receipt span {
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .rgbf-thermal-receipt .receipt-amount {
        min-width: max-content;
        flex-shrink: 0;
        white-space: nowrap;
    }

    .rgbf-thermal-receipt .receipt-columns {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) 40px 82px;
        column-gap: 8px;
        align-items: baseline;
    }

    .rgbf-thermal-receipt .receipt-product-name,
    .rgbf-thermal-receipt .receipt-quantity {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .rgbf-thermal-receipt .receipt-columns .receipt-amount {
        min-width: 0;
        white-space: normal;
        overflow-wrap: anywhere;
    }

    .rgbf-thermal-receipt .receipt-summary-row {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) minmax(0, max-content);
        column-gap: 12px;
        align-items: baseline;
    }

    .rgbf-thermal-receipt .receipt-summary-row .receipt-amount {
        min-width: 0;
        text-align: right;
        white-space: normal;
        overflow-wrap: anywhere;
    }

    .rgbf-thermal-receipt .receipt-meta {
        display: grid !important;
        gap: 2px;
    }

    .rgbf-thermal-receipt img {
        max-width: 100%;
    }
</style>
