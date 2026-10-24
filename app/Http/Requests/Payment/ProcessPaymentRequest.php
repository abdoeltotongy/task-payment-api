<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Models\Order;
use App\Payments\PaymentGatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProcessPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order && $this->user()?->can('pay', $order) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::in($this->enabledMethods())],
            'data' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return list<string>
     */
    private function enabledMethods(): array
    {
        return app(PaymentGatewayManager::class)->enabledMethods();
    }
}
