{{--
    Historical trend chart.

    Below two usable measurements the component says what it has instead of
    drawing it — a one-point chart is a dot a reader will read a direction
    into. "No history at all" and "no history in this range" are separated,
    because widening the range and running a test are different remedies. See
    the component docblock.
--}}
<div class="ap-psi-trend">
    @unless ($uiComponentsInstalled)
        <div class="ap-psi-trend__missing-components" role="alert">
            <p>{{ __( 'The PageSpeed trend chart needs the ArtisanPack UI component library. Install it with:' ) }}</p>
            <pre><code>composer require artisanpack-ui/livewire-ui-components</code></pre>
        </div>
    @else
        <x-artisanpack-card
            :title="__( 'Trend' )"
            :subtitle="$url"
            shadow
        >
            <x-slot:menu>
                <div class="ap-psi-trend__controls flex flex-wrap items-end gap-2">
                    <x-artisanpack-select
                        wire:model.live="metric"
                        :label="__( 'Metric' )"
                        :options="$this->metricOptions()"
                        option-value="value"
                        option-label="label"
                        class="select-sm"
                    />

                    <x-artisanpack-select
                        wire:model.live="range"
                        :label="__( 'Range' )"
                        :options="$this->rangeOptions()"
                        option-value="value"
                        option-label="label"
                        class="select-sm"
                    />
                </div>
            </x-slot:menu>

            @if ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart::STATE_EMPTY)
                <p class="ap-psi-trend__empty">
                    {{ __( 'No PageSpeed test has run for this URL yet. A trend appears once there are at least two results to compare.' ) }}
                </p>
            @elseif ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart::STATE_OUT_OF_RANGE)
                <x-artisanpack-alert
                    color="info"
                    :title="__( 'Nothing tested in this range' )"
                    :description="__( 'This URL has stored results, but none inside the selected range. Choose a wider range to see them.' )"
                />
            @elseif ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart::STATE_INSUFFICIENT)
                <x-artisanpack-alert
                    color="info"
                    :title="__( 'Not enough history yet' )"
                    :description="$hasOlderHistory
                        ? __( 'Only one measurement of :metric falls inside the selected range, and one measurement is not a trend. Choose a wider range to include older results.', [ 'metric' => $this->metricTitle() ] )
                        : __( 'There is only one measurement of :metric so far, and one measurement is not a trend. The chart appears once a second test has run.', [ 'metric' => $this->metricTitle() ] )"
                />
            @else
                <x-artisanpack-chart
                    :series="$series"
                    :options="$this->chartOptions()"
                    type="line"
                    class="ap-psi-trend__chart"
                />

                @if ($hasGaps)
                    {{--
                        The break in the line is the point. A run that lost this
                        measurement is a hole in the history, not a low score,
                        and joining straight over it would hide the very gap
                        that explains an odd-looking trend.
                    --}}
                    <x-artisanpack-alert
                        color="warning"
                        :title="__( 'The line has gaps' )"
                        :description="__( 'Some runs in this range completed without returning :metric, so the line breaks rather than joining across them.', [ 'metric' => $this->metricTitle() ] )"
                        class="mt-4"
                    />
                @endif

                @if ($truncated)
                    {{--
                        Stated rather than passed over. A chart that quietly
                        drops the older half of its range is a chart that
                        answers a question nobody asked.
                    --}}
                    <x-artisanpack-alert
                        color="info"
                        :title="__( 'Showing the most recent results only' )"
                        :description="__( 'This range holds more than :count results, so only the :count most recent are plotted. Choose a narrower range to see the rest in detail.', [ 'count' => \ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart::MAX_RESULTS ] )"
                        class="mt-4"
                    />
                @endif

                <p class="ap-psi-trend__summary text-xs opacity-70 mt-4">
                    {{ trans_choice(
                        ':count measurement of :metric.|:count measurements of :metric.',
                        $pointCount,
                        [ 'count' => $pointCount, 'metric' => $this->metricTitle() ],
                    ) }}
                </p>
            @endif
        </x-artisanpack-card>
    @endunless
</div>
