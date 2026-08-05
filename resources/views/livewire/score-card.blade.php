{{--
    PageSpeed category score card.

    Five states, deliberately worded apart from one another: an empty card
    means "nothing has run yet", and anything else that renders as an empty
    card is a problem nobody notices. See the component docblock.
--}}
<div
    class="ap-psi-score-card"
    @if ($running) wire:poll.{{ \ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard::POLL_SECONDS }}s="refresh" @endif
>
    @unless ($uiComponentsInstalled)
        <div class="ap-psi-score-card__missing-components" role="alert">
            <p>{{ __( 'The PageSpeed score card needs the ArtisanPack UI component library. Install it with:' ) }}</p>
            <pre><code>composer require artisanpack-ui/livewire-ui-components</code></pre>
        </div>
    @else
        <x-artisanpack-card
            :title="__( 'PageSpeed scores' )"
            :subtitle="$url"
            shadow
        >
            <x-slot:menu>
                <x-artisanpack-badge :value="$strategy === 'desktop' ? __( 'Desktop' ) : __( 'Mobile' )" />
            </x-slot:menu>

            @if ($actionMessage)
                <x-artisanpack-alert
                    color="warning"
                    :title="$actionTitle"
                    :description="$actionMessage"
                    class="mb-4"
                />
            @endif

            @if ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard::STATE_NO_API_KEY)
                <x-artisanpack-alert
                    color="warning"
                    :title="__( 'No PageSpeed API key is configured' )"
                    :description="__( 'PageSpeed Insights will not run without an API key — Google\'s anonymous quota is zero, so every keyless request fails.' )"
                />

                <div class="ap-psi-score-card__setup flex flex-col gap-2 mt-4">
                    <p>
                        {{ __( 'Create a key in the Google Cloud Console with the PageSpeed Insights API enabled, then set the :variable environment variable.', [ 'variable' => 'PAGESPEED_API_KEY' ] ) }}
                    </p>
                    <pre><code>PAGESPEED_API_KEY=your-key-here</code></pre>
                    <p class="text-xs opacity-70">
                        {{ __( 'Applications storing the key in the database or the CMS Settings module should set the :key config value instead.', [ 'key' => 'pagespeed-insights.driver' ] ) }}
                    </p>
                </div>
            @elseif ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard::STATE_EMPTY)
                <p class="ap-psi-score-card__empty">
                    {{ __( 'No PageSpeed test has run for this URL yet.' ) }}
                </p>
            @elseif ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard::STATE_FAILED)
                <x-artisanpack-alert
                    color="error"
                    :title="__( 'The last PageSpeed run failed' )"
                    :description="$errorMessage"
                />
            @else
                @if ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard::STATE_DEGRADED)
                    <x-artisanpack-alert
                        color="warning"
                        :title="__( 'The last run completed with data missing' )"
                        :description="__( 'Some of the gauges below are blank because this run did not return them.' )"
                    />

                    <ul class="ap-psi-score-card__warnings list-disc ps-5 mt-2 mb-4 text-sm">
                        @foreach ($warnings as $warning)
                            <li wire:key="psi-warning-{{ $loop->index }}">{{ $warning }}</li>
                        @endforeach
                    </ul>
                @endif

                @php
                    // Written out in full rather than interpolated. A class
                    // name assembled as "progress-{$color}" never appears in
                    // the source Tailwind scans, so the utility is never
                    // generated and every bar renders grey.
                    $progressClasses = [
                        'success' => 'progress-success',
                        'warning' => 'progress-warning',
                        'error'   => 'progress-error',
                    ];
                @endphp

                <div class="ap-psi-score-card__gauges grid grid-cols-2 gap-4 lg:grid-cols-4">
                    @foreach ($gauges as $gauge)
                        <div class="ap-psi-gauge" wire:key="psi-gauge-{{ $gauge['category'] }}">
                            @if ($gauge['available'])
                                <x-artisanpack-stat
                                    :title="$gauge['label']"
                                    :value="(string) $gauge['score']"
                                    :description="$gauge['bandLabel']"
                                    :color="$gauge['color']"
                                    :animate="false"
                                />
                                <x-artisanpack-progress
                                    :value="$gauge['score']"
                                    max="100"
                                    class="w-full mt-2 {{ $progressClasses[ $gauge['color'] ] ?? '' }}"
                                    :aria-label="__( ':category score: :score out of 100', [ 'category' => $gauge['label'], 'score' => $gauge['score'] ] )"
                                />
                            @else
                                {{--
                                    An unscored category is NOT a zero. A zero
                                    gauge and a missing gauge mean opposite
                                    things, so this state carries no number and
                                    no progress bar at all.
                                --}}
                                <x-artisanpack-stat
                                    :title="$gauge['label']"
                                    value="—"
                                    :description="__( 'Unavailable' )"
                                    :animate="false"
                                    class="ap-psi-gauge--unavailable opacity-60"
                                />
                                <p class="ap-psi-gauge__note text-xs opacity-70">
                                    {{ __( 'This run did not return a score for this category.' ) }}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($fetchedAt)
                    <p class="ap-psi-score-card__timestamp text-xs opacity-70 mt-4">
                        {{ __( 'Last tested :timestamp', [ 'timestamp' => $fetchedAt ] ) }}
                    </p>
                @endif
            @endif

            <x-slot:actions>
                @if ($running)
                    <x-artisanpack-loading class="loading-sm" />
                    <span class="text-sm">{{ __( 'Test running…' ) }}</span>
                    {{--
                        A manual check alongside the poll. The poll is what
                        normally ends the wait, but a browser that has
                        backgrounded the tab throttles it hard, and having no
                        way to ask is what makes a slow run feel stuck.
                    --}}
                    <x-artisanpack-button
                        wire:click="refresh"
                        wire:loading.attr="disabled"
                        wire:target="refresh"
                        class="btn-ghost btn-sm"
                    >
                        {{ __( 'Check now' ) }}
                    </x-artisanpack-button>
                @else
                    <x-artisanpack-button
                        wire:click="runTest"
                        wire:loading.attr="disabled"
                        wire:target="runTest"
                        color="primary"
                        :disabled="! $apiKeyConfigured"
                        :tooltip="$apiKeyConfigured ? null : __( 'Configure a PageSpeed API key to run tests.' )"
                    >
                        {{ __( 'Run test' ) }}
                    </x-artisanpack-button>
                @endif
            </x-slot:actions>
        </x-artisanpack-card>
    @endunless
</div>
