<?php

namespace App\Http\Requests;

use App\Models\Unit;
use App\Models\UnitGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'identifier' => ['required', 'string', 'max:255', Rule::unique( Unit::class, 'identifier' )->ignore( (int) $this->route( 'id' ) )],
            'description' => 'nullable|string',
            'group_id' => ['required', 'integer', Rule::exists( UnitGroup::class, 'id' )],
            'value' => 'required|numeric|gt:0',
            'base_unit' => 'required|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ( ! $this->filled( 'identifier' ) && is_string( $this->input( 'name' ) ) ) {
            $unit = $this->isMethod( 'PUT' ) ? Unit::find( (int) $this->route( 'id' ) ) : null;
            $this->merge( ['identifier' => $unit?->identifier ?: Unit::generateIdentifier( $this->input( 'name' ) )] );
        }
    }
}
