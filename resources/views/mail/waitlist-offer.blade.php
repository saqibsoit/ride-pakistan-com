<x-mail::message>
# A place opened up

Good news: {{ $entry->seats === 1 ? 'a seat' : $entry->seats.' seats' }} came free in the session you're waiting for, **{{ $entry->slot->label() }}** (Pakistan time).

We're holding {{ $entry->seats === 1 ? 'it' : 'them' }} for you until **{{ $expires }}** (Pakistan time). After that the offer goes to the next person on the waitlist.

<x-mail::button :url="$bookUrl" color="primary">
Book now
</x-mail::button>

You can pay as usual or use package credits. If you can't make it, you don't need to do anything.

Ride Pakistan
</x-mail::message>
