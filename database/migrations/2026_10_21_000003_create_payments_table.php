<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('method');
            $table->enum('status', ['pending', 'successful', 'failed'])
                ->default(PaymentStatus::Pending->value);
            // Null for pending and failed rows, so the unique index allows only one successful payment per order.
            $table->boolean('is_successful')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('transaction_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'is_successful']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
