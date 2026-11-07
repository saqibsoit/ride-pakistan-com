<?php

declare(strict_types=1);

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

it('links the label, hint and input', function (): void {
    $this->blade('<x-form.field name="email" label="Email" type="email" hint="For your ticket." required autocomplete="email" />')
        ->assertSee('<label for="field-email"', false)
        ->assertSee('id="field-email"', false)
        ->assertSee('type="email"', false)
        ->assertSee('required', false)
        ->assertSee('autocomplete="email"', false)
        ->assertSee('aria-describedby="field-email-hint"', false)
        ->assertDontSee('(optional)')
        ->assertDontSee('aria-invalid', false);
});

it('marks optional fields', function (): void {
    $this->blade('<x-form.field name="phone" label="Phone" />')->assertSee('(optional)');
});

it('shows an explicit error and marks the control invalid', function (): void {
    $this->blade('<x-form.field name="phone" label="Phone" error="Enter a valid number." />')
        ->assertSee('aria-invalid="true"', false)
        ->assertSee('aria-describedby="field-phone-error"', false)
        ->assertSee('id="field-phone-error"', false)
        ->assertSee('Error:')
        ->assertSee('Enter a valid number.')
        ->assertSee('border-error', false);
});

it('reads errors from the validation bag', function (): void {
    $errors = (new ViewErrorBag)->put('default', new MessageBag(['name' => ['Tell us your name.']]));

    view()->share('errors', $errors);

    $this->blade('<x-form.field name="name" label="Name" />')
        ->assertSee('Tell us your name.')
        ->assertSee('aria-invalid="true"', false);
});

it('converts dot names to array names', function (): void {
    $this->blade('<x-form.field name="riders.0.name" label="Rider name" />')
        ->assertSee('name="riders[0][name]"', false)
        ->assertSee('id="field-riders-0-name"', false);
});

it('renders textarea and select controls', function (): void {
    $this->blade('<x-form.field name="message" label="Message" type="textarea" rows="4" value="Hi" />')
        ->assertSee('<textarea', false)
        ->assertSee('rows="4"', false)
        ->assertSee('>Hi</textarea>', false);

    $this->blade('<x-form.field name="slot" label="Slot" type="select"><option value="am">Morning</option></x-form.field>')
        ->assertSee('<select', false)
        ->assertSee('<option value="am">Morning</option>', false);
});

it('never echoes a password value', function (): void {
    $this->blade('<x-form.field name="password" label="Password" type="password" value="secret" />')
        ->assertDontSee('secret');
});
