{{--
    Sibling Ogua products as bare <li> links, for a site's own footer/nav to style.
    Data comes from App\Services\OguaFamily (company site feed, cached daily).
--}}
@props([
    'products' => app(\App\Services\OguaFamily::class)->products(),
    'withCompany' => false,
    'showCategory' => false,
    'linkClass' => '',
])

@foreach ($products as $familyProduct)
    @continue(! $familyProduct['href'])
    <li>
        <a href="{{ $familyProduct['href'] }}" class="{{ $linkClass }}" rel="noopener">
            {{ $familyProduct['name'] }}
            @if ($showCategory && $familyProduct['category'])
                <span class="ogua-family-category">{{ $familyProduct['category'] }}</span>
            @endif
        </a>
    </li>
@endforeach

@if ($withCompany)
    @php($familyCompany = app(\App\Services\OguaFamily::class)->company())
    <li>
        <a href="{{ $familyCompany['url'] }}" class="{{ $linkClass }}" rel="noopener">{{ $familyCompany['name'] }}</a>
    </li>
@endif
