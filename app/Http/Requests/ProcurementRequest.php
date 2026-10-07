<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcurementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return ns()->allowedTo( ['nexopos.create.procurements'] );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'general.purchase_order_id' => 'prohibited',
            'general.billing_approval_status' => 'prohibited',
            'general.billing_approved_by' => 'prohibited',
            'general.billing_approved_at' => 'prohibited',
            'general.branch_id' => 'prohibited',
            'general.delivery_status' => 'required',
            'general.payment_status' => 'required',
            'general.provider_id' => 'required',
        ];
    }
}
