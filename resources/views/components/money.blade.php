{{-- <x-money :minor="1000000" /> → "PKR 10,000". Amounts are integer minor units. --}}
@props(['minor', 'currency' => 'PKR'])
<span {{ $attributes }}>{{ \App\Support\Money::format((int) $minor, $currency) }}</span>
