<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order && $this->user()?->can('update', $order) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'array', 'min:1'],
            ...OrderItemRules::itemFields(),
        ];
    }
}
