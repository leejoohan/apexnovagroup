<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class ProductIndexRequest extends PaginationRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'min_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'max_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'min_stock' => ['sometimes', 'integer', 'between:0,4294967295'],
            'max_stock' => ['sometimes', 'integer', 'between:0,4294967295'],
            'stock_status' => ['sometimes', 'in:out_of_stock,low_stock,in_stock'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ([['min_price', 'max_price'], ['min_stock', 'max_stock']] as [$lower, $upper]) {
                $lowerValue = $this->input($lower);
                $upperValue = $this->input($upper);
                if (is_numeric($lowerValue) && is_numeric($upperValue) && (float) $lowerValue > (float) $upperValue) {
                    $validator->errors()->add($upper, "The {$upper} field must be at least {$lower}.");
                }
            }
        });
    }
}
