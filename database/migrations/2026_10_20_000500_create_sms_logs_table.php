<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sms_logs')) {
            return;
        }

        Schema::create('sms_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('to', 32)->index();
            $table->text('message');
            $table->string('template_key', 120)->nullable()->index();
            $table->string('status', 20)->default('queued')->index();
            $table->string('provider', 40)->default('africastalking');
            $table->string('provider_message_id', 120)->nullable()->index();
            $table->decimal('cost', 12, 4)->nullable();
            $table->string('currency', 8)->nullable();
            $table->unsignedSmallInteger('segments')->default(1);
            $table->text('error')->nullable();
            $table->json('context')->nullable();
            $table->nullableMorphs('related');
            $table->boolean('urgent')->default(false);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('send_after')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
