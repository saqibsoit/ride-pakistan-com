<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weather alerts (milestone 10f). weather_forecast_hours caches the latest
 * Open-Meteo hourly forecast for the venue (UTC hours), so badges and
 * assessments never call the API on a page load. weather_fetches records
 * every attempt, for the "forecasts failing" alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_forecast_hours', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('hour_at')->unique()->comment('UTC, start of the hour');
            $table->unsignedTinyInteger('precipitation_probability')->nullable()->comment('%');
            $table->decimal('precipitation_mm', 6, 2)->nullable();
            $table->decimal('wind_speed_kmh', 5, 1)->nullable();
            $table->decimal('wind_gusts_kmh', 5, 1)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedSmallInteger('weather_code')->nullable()->comment('WMO code');
            $table->dateTime('fetched_at');
            $table->timestamps();
        });

        Schema::create('weather_fetches', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('attempted_at')->index();
            $table->boolean('succeeded')->index();
            $table->string('error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('slot_weather', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('slot_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('severity', 20);
            $table->json('conditions');
            $table->dateTime('assessed_at');
            $table->string('alerted_severity', 20)->nullable();
            $table->dateTime('alerted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_weather');
        Schema::dropIfExists('weather_fetches');
        Schema::dropIfExists('weather_forecast_hours');
    }
};
