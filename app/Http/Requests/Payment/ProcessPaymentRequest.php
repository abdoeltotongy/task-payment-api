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
        $enabledMethods = $this->enabledMethods();

        $rules = [
            'method' => ['required', 'string', Rule::in($enabledMethods)],
            'data' => ['sometimes', 'array'],
        ];

        $method = $this->input('method');

        if (! is_string($method) || ! in_array($method, $enabledMethods, true)) {
            return $rules;
        }

        foreach (app(PaymentGatewayManager::class)->driver($method)->rules() as $field => $fieldRules) {
            $rules['data.'.$field] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function enabledMethods(): array
    {
        return app(PaymentGatewayManager::class)->enabledMethods();
    }
}
