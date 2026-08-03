{{--
    Lighthouse opportunities table.

    An empty list is three different pieces of news — performance was never
    measured, performance was measured and came back clean, or the run failed
    outright — and only one of them is good. See the component docblock.
--}}
<div class="ap-psi-opportunities">
    @unless ($uiComponentsInstalled)
        <div class="ap-psi-opportunities__missing-components" role="alert">
            <p>{{ __( 'The PageSpeed opportunities table needs the ArtisanPack UI component library. Install it with:' ) }}</p>
            <pre><code>composer require artisanpack-ui/livewire-ui-components</code></pre>
        </div>
    @else
        <x-artisanpack-card
            :title="__( 'Opportunities' )"
            :subtitle="$url"
            shadow
        >
            <x-slot:menu>
                <x-artisanpack-badge :value="$strategy === 'desktop' ? __( 'Desktop' ) : __( 'Mobile' )" />
            </x-slot:menu>

            @if ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\OpportunitiesTable::STATE_EMPTY)
                <p class="ap-psi-opportunities__empty">
                    {{ __( 'No PageSpeed test has run for this URL yet.' ) }}
                </p>
            @elseif ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\OpportunitiesTable::STATE_FAILED)
                <x-artisanpack-alert
                    color="error"
                    :title="__( 'The last PageSpeed run failed' )"
                    :description="$errorMessage"
                />
            @elseif ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\OpportunitiesTable::STATE_NOT_MEASURED)
                {{--
                    Nothing was found because nothing was looked for. Rendering
                    this as "no opportunities" would report a clean page on the
                    strength of a test that never examined it.
                --}}
                <x-artisanpack-alert
                    color="warning"
                    :title="__( 'Performance was not measured' )"
                    :description="__( 'This run did not request the performance category, so Lighthouse collected no opportunity audits. There is nothing to report rather than nothing to fix.' )"
                />
            @elseif ($state === \ArtisanPackUI\PageSpeedInsights\Livewire\OpportunitiesTable::STATE_NONE)
                <x-artisanpack-alert
                    color="success"
                    :title="__( 'No opportunities found' )"
                    :description="__( 'Lighthouse measured this page and found nothing above its own reporting threshold worth changing.' )"
                />
            @else
                @php
                    $opportunityHeaders = [
                        [ 'key' => 'title', 'label' => __( 'Opportunity' ) ],
                        [ 'key' => 'savings', 'label' => __( 'Estimated saving' ), 'class' => 'text-end' ],
                        [ 'key' => 'score', 'label' => __( 'Audit score' ), 'class' => 'text-end' ],
                    ];
                @endphp

                <x-artisanpack-table
                    :headers="$opportunityHeaders"
                    :rows="$rows"
                    key-by="id"
                    class="ap-psi-opportunities__table"
                >
                    @scope('cell_title', $row)
                        <div class="ap-psi-opportunity">
                            <span class="ap-psi-opportunity__title font-medium">{{ $row['title'] }}</span>

                            @if ($row['description'])
                                <p class="ap-psi-opportunity__description text-xs opacity-70">
                                    {{ $row['description'] }}
                                </p>
                            @endif
                        </div>
                    @endscope

                    @scope('cell_savings', $row)
                        <div class="ap-psi-opportunity__savings text-end">
                            @if ($row['savings'])
                                <span class="ap-psi-opportunity__savings-value font-medium">{{ $row['savings'] }}</span>
                            @else
                                {{-- No estimate is not an estimate of zero. --}}
                                <span class="ap-psi-opportunity__savings-value opacity-60">—</span>
                            @endif

                            @if ($row['displayValue'])
                                <p class="ap-psi-opportunity__display-value text-xs opacity-70">
                                    {{ $row['displayValue'] }}
                                </p>
                            @endif
                        </div>
                    @endscope

                    @scope('cell_score', $row)
                        <div class="ap-psi-opportunity__score text-end">
                            @if ($row['score'] !== null)
                                <x-artisanpack-badge
                                    :value="(string) $row['score']"
                                    :color="$row['color']"
                                />
                            @else
                                <span class="opacity-60">{{ __( 'Unscored' ) }}</span>
                            @endif
                        </div>
                    @endscope
                </x-artisanpack-table>

                <p class="ap-psi-opportunities__note text-xs opacity-70 mt-4">
                    {{ __( 'Savings are Lighthouse\'s own estimates of what fixing each item would buy, and are not additive.' ) }}
                </p>
            @endif

            @if ($fetchedAt)
                <p class="ap-psi-opportunities__timestamp text-xs opacity-70">
                    {{ __( 'Last tested :timestamp', [ 'timestamp' => $fetchedAt ] ) }}
                </p>
            @endif
        </x-artisanpack-card>
    @endunless
</div>
