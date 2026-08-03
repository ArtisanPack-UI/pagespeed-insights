{{--
    Monitored URL manager.

    The at-a-glance column distinguishes "never tested", "the last run failed",
    "the last run returned no score", and an actual score. Collapsing any of
    those into a blank cell would show a page that stopped being testable as
    one that is merely quiet. See the component docblock.
--}}
<div class="ap-psi-urls">
    @unless ($uiComponentsInstalled)
        <div class="ap-psi-urls__missing-components" role="alert">
            <p>{{ __( 'The PageSpeed URL manager needs the ArtisanPack UI component library. Install it with:' ) }}</p>
            <pre><code>composer require artisanpack-ui/livewire-ui-components</code></pre>
        </div>
    @else
        @php
            // Resolved once and handed to the scoped slot rather than called
            // inside it: the per-row select offers the same cadences on every
            // row, and the "default" option names a cadence read from config.
            $frequencyOptions = $this->frequencyOptions();
        @endphp

        <x-artisanpack-card
            :title="__( 'Monitored URLs' )"
            :subtitle="trans_choice( ':count URL monitored|:count URLs monitored', $totalCount, [ 'count' => $totalCount ] )"
            shadow
        >
            @if ($statusMessage)
                <x-artisanpack-alert
                    :color="$statusColor"
                    :title="$statusTitle"
                    :description="$statusMessage"
                    class="mb-4"
                />
            @endif

            <form wire:submit="add" class="ap-psi-urls__add flex flex-wrap items-start gap-2 mb-6">
                <x-artisanpack-input
                    wire:model="newUrl"
                    :label="__( 'URL' )"
                    :placeholder="__( 'https://example.com/pricing' )"
                    class="grow"
                />

                <x-artisanpack-input
                    wire:model="newLabel"
                    :label="__( 'Label' )"
                    :placeholder="__( 'Optional' )"
                />

                <x-artisanpack-button
                    type="submit"
                    color="primary"
                    wire:loading.attr="disabled"
                    wire:target="add"
                    class="self-end"
                >
                    {{ __( 'Add URL' ) }}
                </x-artisanpack-button>
            </form>

            @if ($rows === [])
                <p class="ap-psi-urls__empty">
                    {{ __( 'No URLs are monitored yet. Add one above, or import the ones your sitemap lists.' ) }}
                </p>
            @else
                @php
                    $urlHeaders = [
                        [ 'key' => 'url', 'label' => __( 'URL' ) ],
                        [ 'key' => 'performance', 'label' => __( 'Performance' ) ],
                        [ 'key' => 'frequency', 'label' => __( 'Tested' ) ],
                        [ 'key' => 'actions', 'label' => __( 'Actions' ), 'class' => 'text-end' ],
                    ];
                @endphp

                <x-artisanpack-table
                    :headers="$urlHeaders"
                    :rows="$rows"
                    key-by="key"
                    class="ap-psi-urls__table"
                >
                    @scope('cell_url', $row)
                        <div class="ap-psi-url">
                            @if ($row['label'])
                                <span class="ap-psi-url__label font-medium">{{ $row['label'] }}</span>
                            @endif

                            <p class="ap-psi-url__address text-xs opacity-70 break-all">{{ $row['url'] }}</p>

                            <div class="ap-psi-url__badges flex flex-wrap items-center gap-1 mt-1">
                                <x-artisanpack-badge :value="$row['sourceLabel']" class="badge-sm badge-ghost" />

                                @unless ($row['isActive'])
                                    <x-artisanpack-badge :value="__( 'Paused' )" color="warning" class="badge-sm" />
                                @endunless
                            </div>
                        </div>
                    @endscope

                    @scope('cell_performance', $row)
                        {{--
                            Only the form factors this row actually tests get a
                            cell: a blank desktop figure on a mobile-only URL
                            reads as a desktop run that failed.
                        --}}
                        <div class="ap-psi-url__scores flex flex-wrap gap-3">
                            @foreach ($row['measurements'] as $measurement)
                                <div class="ap-psi-url__score text-xs">
                                    <span class="opacity-70">{{ $measurement['label'] }}</span>

                                    @if ($measurement['state'] === \ArtisanPackUI\PageSpeedInsights\Livewire\UrlManager::MEASUREMENT_SCORED)
                                        <x-artisanpack-badge
                                            :value="(string) $measurement['score']"
                                            :color="$measurement['color']"
                                            :title="$measurement['bandLabel']"
                                        />
                                    @elseif ($measurement['state'] === \ArtisanPackUI\PageSpeedInsights\Livewire\UrlManager::MEASUREMENT_FAILED)
                                        {{-- Never the last good score. A green number on a page that stopped being testable is the worst reading this table has. --}}
                                        <x-artisanpack-badge
                                            :value="__( 'Failed' )"
                                            color="error"
                                            class="badge-sm"
                                        />
                                    @elseif ($measurement['state'] === \ArtisanPackUI\PageSpeedInsights\Livewire\UrlManager::MEASUREMENT_UNAVAILABLE)
                                        {{-- No score is not a score of zero. --}}
                                        <span class="ap-psi-url__score-unavailable opacity-60" title="{{ __( 'The last run did not return a performance score.' ) }}">
                                            {{ __( 'No score' ) }}
                                        </span>
                                    @else
                                        <span class="ap-psi-url__score-none opacity-60">{{ __( 'Not tested yet' ) }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endscope

                    @scope('cell_frequency', $row, $frequencyOptions)
                        <div class="ap-psi-url__frequency">
                            @if ($row['editable'])
                                <x-artisanpack-select
                                    wire:model.live="frequencies.{{ $row['id'] }}"
                                    :options="$frequencyOptions"
                                    option-value="value"
                                    option-label="label"
                                    :aria-label="__( 'How often :url is tested', [ 'url' => $row['url'] ] )"
                                    class="select-sm"
                                />
                            @else
                                {{--
                                    A hook-contributed row is owned by the
                                    package that registered it, so its cadence
                                    is reported rather than offered.
                                --}}
                                <span class="text-sm">{{ $row['frequencyLabel'] }}</span>
                            @endif

                            <p class="ap-psi-url__last-tested text-xs opacity-70 mt-1">
                                @if ($row['lastTestedAt'])
                                    {{ __( 'Last tested :timestamp', [ 'timestamp' => $row['lastTestedAt'] ] ) }}
                                @else
                                    {{ __( 'Never tested' ) }}
                                @endif
                            </p>
                        </div>
                    @endscope

                    @scope('cell_actions', $row, $pendingRemovalId)
                        <div class="ap-psi-url__actions flex flex-wrap items-center justify-end gap-2">
                            @if (! $row['editable'])
                                <span class="text-xs opacity-70">
                                    {{ __( 'Registered by another package' ) }}
                                </span>
                            @elseif ($pendingRemovalId === $row['id'])
                                {{--
                                    Two-step because there is no undo: deleting
                                    a URL discards every measurement ever taken
                                    of that page.
                                --}}
                                <span class="text-xs">{{ __( 'Delete this URL and its history?' ) }}</span>

                                <x-artisanpack-button
                                    wire:click="remove"
                                    wire:loading.attr="disabled"
                                    wire:target="remove"
                                    color="error"
                                    class="btn-sm"
                                >
                                    {{ __( 'Delete' ) }}
                                </x-artisanpack-button>

                                <x-artisanpack-button
                                    wire:click="cancelRemoval"
                                    class="btn-ghost btn-sm"
                                >
                                    {{ __( 'Cancel' ) }}
                                </x-artisanpack-button>
                            @else
                                <x-artisanpack-button
                                    wire:click="toggleActive({{ $row['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="toggleActive({{ $row['id'] }})"
                                    class="btn-ghost btn-sm"
                                >
                                    {{ $row['isActive'] ? __( 'Pause' ) : __( 'Resume' ) }}
                                </x-artisanpack-button>

                                <x-artisanpack-button
                                    wire:click="confirmRemoval({{ $row['id'] }})"
                                    class="btn-ghost btn-sm text-error"
                                >
                                    {{ __( 'Remove' ) }}
                                </x-artisanpack-button>
                            @endif
                        </div>
                    @endscope
                </x-artisanpack-table>

                @if ($truncated)
                    {{--
                        Said out loud. A list that quietly shows a prefix of the
                        monitored set is a list an operator will trust to be all
                        of it.
                    --}}
                    <x-artisanpack-alert
                        color="info"
                        :title="__( 'Showing the first :count URLs', [ 'count' => \ArtisanPackUI\PageSpeedInsights\Livewire\UrlManager::MAX_URLS ] )"
                        :description="__( 'This installation monitors :total URLs, and this table lists the first :count. Use the console commands to work with the rest.', [ 'total' => $totalCount, 'count' => \ArtisanPackUI\PageSpeedInsights\Livewire\UrlManager::MAX_URLS ] )"
                        class="mt-4"
                    />
                @endif
            @endif

            <x-slot:actions>
                <span class="text-xs opacity-70 me-auto">
                    {{ __( 'Sitemap: :sitemap', [ 'sitemap' => $sitemapUrl ] ) }}
                </span>

                <x-artisanpack-button
                    wire:click="importSitemap"
                    wire:loading.attr="disabled"
                    wire:target="importSitemap"
                    class="btn-sm"
                >
                    {{ __( 'Import from sitemap' ) }}
                </x-artisanpack-button>
            </x-slot:actions>
        </x-artisanpack-card>
    @endunless
</div>
