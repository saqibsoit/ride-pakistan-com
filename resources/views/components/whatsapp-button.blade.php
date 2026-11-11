{{--
    Floating "Chat with us on WhatsApp" link (prompt 14). A plain wa.me link:
    no third-party script, so no CSP entry. Number and message come from
    Settings (contact.whatsapp_number / contact.whatsapp_message); nothing
    renders while the number is empty. resources/js/tracking.js sends a
    Contact event on click, only after analytics consent.
--}}
@php($href = app(\App\Support\WhatsAppLink::class)->contact())

@if ($href !== null)
    <a
        href="{{ $href }}"
        target="_blank"
        rel="noopener"
        data-track="contact"
        data-whatsapp-button
        aria-label="Chat with us on WhatsApp (opens in a new tab)"
        class="whatsapp-fab fixed z-30 inline-flex size-14 items-center justify-center rounded-full border border-earth bg-sand text-earth transition-colors duration-200 hover:bg-earth hover:text-sand hover:border-sand print:hidden"
    >
        <x-glyph name="chat" class="size-7" />
    </a>
@endif
