{{--
    Core Web Vitals card.

    "Not enough field data" and "showing origin-level data" are two different
    states with two different messages. Showing origin-level numbers as if
    they were page-level numbers misrepresents the page. See the component
    docblock.
--}}
<div class="ap-psi-cwv-card">
    @unless ($uiComponentsInstalled)
        <div class="ap-psi-cwv-card__missing-components" role="alert">
            <p>{{ __( 'The Core Web Vitals card needs the ArtisanPack UI component library. Install it with:' ) }}</p>
            <pre><code>composer require artisanpack-ui/livewire-ui-components</code></pre>
        </div>
    @else
        <x-artisanpack-card
            :title="__( 'Core Web Vitals' )"
            :subtitle="$url"
            shadow
        >
            <x-slot:menu>
                <x-artisanpack-badge :value="$strategy === 'desktop' ? __( 'Desktop' ) : __( 'Mobile' )" />
            </x-slot:menu>

            @if ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\CoreWebVitalsCard::STATE_EMPTY)
                <p class="ap-psi-cwv-card__empty">
                    {{ __( 'No PageSpeed test has run for this URL yet.' ) }}
                </p>
            @elseif ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\CoreWebVitalsCard::STATE_FAILED)
                <x-artisanpack-alert
                    color="error"
                    :title="__( 'The last PageSpeed run failed' )"
                    :description="$errorMessage"
                />
            @elseif ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\CoreWebVitalsCard::STATE_NO_FIELD_DATA)
                <x-artisanpack-alert
                    color="info"
                    :title="__( 'Not enough field data' )"
                    :description="__( 'The Chrome UX Report has no real-user measurements for this page, and none for the site as a whole either. This is normal for a page with low traffic — it is not an error, and there is nothing to fix.' )"
                />
            @else
                @if ($originLevel)
                    <x-artisanpack-alert
                        color="warning"
                        :title="__( 'Showing site-wide data' )"
                        :description="$dataSubject
                            ? __( 'The Chrome UX Report has too little traffic for this page on its own, so these numbers describe :origin as a whole rather than this page.', [ 'origin' => $dataSubject ] )
                            : __( 'The Chrome UX Report has too little traffic for this page on its own, so these numbers describe the site as a whole rather than this page.' )"
                        class="mb-4"
                    />
                @endif

                <div class="ap-psi-cwv-card__vitals grid grid-cols-1 gap-4 md:grid-cols-3">
                    @foreach ($vitals as $vital)
                        <div class="ap-psi-vital" wire:key="psi-vital-{{ $vital['metric'] }}">
                            @if ($vital['available'])
                                <x-artisanpack-stat
                                    :title="$vital['abbreviation']"
                                    :value="$vital['display']"
                                    :description="$vital['bandLabel']"
                                    :color="$vital['color']"
                                    :tooltip="$vital['label']"
                                    :animate="false"
                                />
                            @else
                                <x-artisanpack-stat
                                    :title="$vital['abbreviation']"
                                    value="—"
                                    :description="__( 'No data' )"
                                    :tooltip="$vital['label']"
                                    :animate="false"
                                    class="ap-psi-vital--unavailable opacity-60"
                                />
                            @endif
                        </div>
                    @endforeach
                </div>

                <p class="ap-psi-cwv-card__percentile text-xs opacity-70 mt-4">
                    {{ __( 'Real-user data from the Chrome UX Report, reported at the :percentile percentile.', [ 'percentile' => $percentile ] ) }}
                </p>

                @if ($fetchedAt)
                    <p class="ap-psi-cwv-card__timestamp text-xs opacity-70">
                        {{ __( 'Last tested :timestamp', [ 'timestamp' => $fetchedAt ] ) }}
                    </p>
                @endif
            @endif
        </x-artisanpack-card>
    @endunless
</div>
