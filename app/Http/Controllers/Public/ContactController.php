<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Leads\CaptureLead;
use App\Enums\LeadInterest;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadRequest;
use App\Models\Page;
use App\Support\BusinessFacts;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /contact: lead form. Not response-cached (CSRF token, flash messages and
 * per-visit attribution fields).
 */
final class ContactController extends Controller
{
    public function show(Request $request, BusinessFacts $facts, Seo $seo): View
    {
        $attribution = [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'] as $field) {
            $value = $request->query($field);
            $attribution[$field] = is_string($value) ? mb_substr($value, 0, 255) : null;
        }

        $attribution['landing_url'] = mb_substr($request->fullUrl(), 0, 2000);
        $referrer = $request->headers->get('referer');
        $attribution['referrer'] = $referrer === null ? null : mb_substr($referrer, 0, 2000);

        $interest = $request->query('interest');

        return view('public.contact', [
            'facts' => $facts,
            'attribution' => array_filter($attribution, fn (?string $value): bool => $value !== null && $value !== ''),
            'interests' => LeadInterest::cases(),
            'selectedInterest' => is_string($interest) ? LeadInterest::tryFrom($interest) : null,
            'seo' => $seo->make(
                title: 'Contact us',
                description: 'Questions about horse riding in Islamabad? Message Ride Pakistan on Instagram, email us, or send the form and we will get back to you.',
                page: Page::query()->firstWhere('slug', 'contact'),
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Contact'],
                ],
            ),
        ]);
    }

    public function store(StoreLeadRequest $request, CaptureLead $capture): RedirectResponse
    {
        // Honeypot filled: look successful, store nothing.
        if (! $request->isSpam()) {
            /** @var array{name: string, phone?: string|null, email?: string|null, interest?: string|null, preferred_date?: string|null, message?: string|null} $details */
            $details = $request->safe()->only(['name', 'phone', 'email', 'interest', 'preferred_date', 'message']);

            $capture->handle($details, $request->safe()->only(CaptureLead::ATTRIBUTION_FIELDS));
        }

        return redirect()->to(route('contact').'#contact-form')->with('status', 'lead-sent');
    }
}
