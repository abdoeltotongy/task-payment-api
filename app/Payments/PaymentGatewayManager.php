<?php

declare(strict_types=1);

namespace App\Payments;

use App\Exceptions\UnsupportedPaymentMethodException;
use App\Payments\Contracts\PaymentGatewayInterface;
use Illuminate\Contracts\Container\Container;

final class PaymentGatewayManager
{
    public function __construct(private readonly Container $container) {}

    public function driver(string $method): PaymentGatewayInterface
    {
        $definition = $this->definitions()[$method] ?? null;

        if (! is_array($definition) || ! $this->isEnabled($definition['enabled'] ?? false)) {
            throw new UnsupportedPaymentMethodException($method);
        }

        $class = $definition['class'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, PaymentGatewayInterface::class)) {
            throw new UnsupportedPaymentMethodException($method);
        }

        $gateway = $this->container->make($class, [
            'config' => is_array($definition['config'] ?? null) ? $definition['config'] : [],
        ]);

        if (! $gateway instanceof PaymentGatewayInterface) {
            throw new UnsupportedPaymentMethodException($method);
        }

        return $gateway;
    }

    /**
     * Register a gateway at runtime. Controllers, services, and validation read this config.
     *
     * @param  array<string, mixed>  $config
     */
    public function extend(string $method, string $class, array $config = [], bool $enabled = true): void
    {
        $gateways = $this->definitions();
        $gateways[$method] = [
            'class' => $class,
            'enabled' => $enabled,
            'config' => $config,
        ];

        config(['payment.gateways' => $gateways]);
    }

    /**
     * @return list<string>
     */
    public function enabledMethods(): array
    {
        $methods = [];

        foreach ($this->definitions() as $method => $definition) {
            if (is_string($method) && is_array($definition) && $this->isEnabled($definition['enabled'] ?? false)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * @return array<string, mixed>
     */
    private function definitions(): array
    {
        $gateways = config('payment.gateways', []);

        return is_array($gateways) ? $gateways : [];
    }

    private function isEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $value;
    }
}
