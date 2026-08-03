/**
 * React monitored URL manager.
 *
 * The React half of `\ArtisanPackUI\PageSpeedInsights\Livewire\UrlManager`.
 *
 * Rows contributed through `ap.pageSpeed.registerUrls` are listed because they
 * cost quota on every cycle exactly like a stored row does, but they are not
 * editable here: they are owned by whichever package registered them, and the
 * next request would rebuild them from the filter whatever this screen did.
 */

import { useCallback, useState, type FormEvent } from 'react'
import { Card } from '@artisanpack-ui/react/layout'
import { Badge, Table, type TableHeader } from '@artisanpack-ui/react/data'
import { Loading } from '@artisanpack-ui/react/feedback'
import { Button, Input } from '@artisanpack-ui/react/form'
import { PageSpeedInsightsError } from '../shared/client'
import { formatTimestamp } from '../shared/labels'
import {
    URLS_MAX,
    createPsiUrl,
    deletePsiUrl,
    fetchPsiUrls,
    type PsiMonitoredUrl,
    type PsiMonitoredUrlsData,
} from '../shared/urls'
import { usePsiResource } from './use-psi-resource'
import { TitledAlert } from './TitledAlert'

/**
 * Props for {@link UrlManager}.
 */
export interface UrlManagerProps {
    /** Path the package's routes are mounted under. @defaultValue '/pagespeed' */
    endpointBase?: string
    /** Fetch instance override, useful for SSR and for tests. */
    fetchImpl?: typeof fetch
    /** CSRF token for the writing endpoints. Read from the document when omitted. */
    csrfToken?: string | null
}

/**
 * What the manager is currently telling the operator.
 */
interface StatusMessage {
    color: 'info' | 'success' | 'warning' | 'error'
    title: string
    message: string
}

/**
 * The monitored set, as something an operator can change.
 */
export function UrlManager( props: UrlManagerProps ) {
    const { endpointBase, fetchImpl, csrfToken } = props

    const load = useCallback(
        ( signal: AbortSignal ) => fetchPsiUrls( { endpointBase, fetchImpl, signal } ),
        [ endpointBase, fetchImpl ],
    )

    const { data, error, loading, reload } = usePsiResource<PsiMonitoredUrlsData>( load )

    const [ newUrl, setNewUrl ] = useState<string>( '' )
    const [ newLabel, setNewLabel ] = useState<string>( '' )
    const [ status, setStatus ] = useState<StatusMessage | null>( null )
    const [ pendingRemovalId, setPendingRemovalId ] = useState<number | null>( null )
    const [ busy, setBusy ] = useState<boolean>( false )

    const add = useCallback(
        async ( event: FormEvent<HTMLFormElement> ) => {
            event.preventDefault()
            setBusy( true )
            setStatus( null )

            try {
                const stored = await createPsiUrl(
                    newUrl,
                    newLabel.trim() ? { label: newLabel.trim() } : {},
                    { endpointBase, fetchImpl, csrfToken },
                )

                setNewUrl( '' )
                setNewLabel( '' )
                setStatus( {
                    color: 'success',
                    title: 'URL added',
                    message: `${ stored.url } is now monitored.`,
                } )
                reload()
            } catch ( thrown: unknown ) {
                setStatus( {
                    color: 'error',
                    title: 'That URL could not be added',
                    message:
                        thrown instanceof PageSpeedInsightsError ? thrown.message : String( thrown ),
                } )
            } finally {
                setBusy( false )
            }
        },
        [ newUrl, newLabel, endpointBase, fetchImpl, csrfToken, reload ],
    )

    const remove = useCallback(
        async ( id: number ) => {
            setBusy( true )
            setStatus( null )

            try {
                await deletePsiUrl( id, { endpointBase, fetchImpl, csrfToken } )
                setPendingRemovalId( null )
                setStatus( {
                    color: 'success',
                    title: 'URL removed',
                    message: 'It is no longer monitored. Its stored results were kept.',
                } )
                reload()
            } catch ( thrown: unknown ) {
                setStatus( {
                    color: 'error',
                    title: 'That URL could not be removed',
                    message:
                        thrown instanceof PageSpeedInsightsError ? thrown.message : String( thrown ),
                } )
            } finally {
                setBusy( false )
            }
        },
        [ endpointBase, fetchImpl, csrfToken, reload ],
    )

    const headers: TableHeader<PsiMonitoredUrl>[] = [
        {
            key: 'url',
            label: 'URL',
            render: ( _value, row ) => (
                <div className="ap-psi-url">
                    { row.label ? <span className="ap-psi-url__label font-medium">{ row.label }</span> : null }

                    <p className="ap-psi-url__address text-xs opacity-70 break-all">{ row.url }</p>

                    <div className="ap-psi-url__badges flex flex-wrap items-center gap-1 mt-1">
                        <Badge value={ row.source } color="ghost" size="sm" />

                        { row.isActive ? null : <Badge value="Paused" color="warning" size="sm" /> }
                    </div>
                </div>
            ),
        },
        {
            key: 'strategies',
            label: 'Tested on',
            render: ( _value, row ) => (
                <span className="ap-psi-url__strategies text-xs">{ row.strategies.join( ', ' ) }</span>
            ),
        },
        {
            key: 'frequency',
            label: 'Tested',
            render: ( _value, row ) => (
                <div className="ap-psi-url__frequency">
                    <span className="text-sm">{ row.frequency }</span>

                    <p className="ap-psi-url__last-tested text-xs opacity-70 mt-1">
                        { row.lastTestedAt ? `Last tested ${ formatTimestamp( row.lastTestedAt ) }` : 'Never tested' }
                    </p>
                </div>
            ),
        },
        {
            key: 'actions',
            label: 'Actions',
            className: 'text-end',
            render: ( _value, row ) => (
                <div className="ap-psi-url__actions flex flex-wrap items-center justify-end gap-2">
                    { ! row.editable || null === row.id ? (
                        <span className="text-xs opacity-70">Registered by another package</span>
                    ) : pendingRemovalId === row.id ? (
                        /*
                            Two-step because there is no undo for the operator's
                            intent, even though the history survives: a URL that
                            vanishes from the list mid-click is a URL somebody
                            has to remember the address of.
                        */
                        <>
                            <span className="text-xs">Stop monitoring this URL?</span>

                            <Button
                                color="error"
                                size="sm"
                                disabled={ busy }
                                onClick={ () => remove( row.id as number ) }
                            >
                                Remove
                            </Button>

                            <Button color="ghost" size="sm" onClick={ () => setPendingRemovalId( null ) }>
                                Cancel
                            </Button>
                        </>
                    ) : (
                        <Button
                            color="ghost"
                            size="sm"
                            className="text-error"
                            onClick={ () => setPendingRemovalId( row.id ) }
                        >
                            Remove
                        </Button>
                    ) }
                </div>
            ),
        },
    ]

    const rows = data?.urls ?? []
    const total = data?.total ?? 0

    return (
        <div className="ap-psi-urls">
            <Card
                title="Monitored URLs"
                subtitle={ 1 === total ? '1 URL monitored' : `${ total } URLs monitored` }
            >
                { status ? (
                    <TitledAlert
                        color={ status.color }
                        title={ status.title }
                        description={ status.message }
                        className="mb-4"
                    />
                ) : null }

                <form onSubmit={ add } className="ap-psi-urls__add flex flex-wrap items-start gap-2 mb-6">
                    <Input
                        label="URL"
                        placeholder="https://example.com/pricing"
                        value={ newUrl }
                        onChange={ ( event ) => setNewUrl( event.target.value ) }
                        className="grow"
                    />

                    <Input
                        label="Label"
                        placeholder="Optional"
                        value={ newLabel }
                        onChange={ ( event ) => setNewLabel( event.target.value ) }
                    />

                    <Button type="submit" color="primary" disabled={ busy } className="self-end">
                        Add URL
                    </Button>
                </form>

                { error ? (
                    <TitledAlert
                        color="error"
                        title="The monitored set could not be loaded"
                        description={ error.message }
                    />
                ) : loading && null === data ? (
                    <p className="ap-psi-urls__loading flex items-center gap-2">
                        <Loading size="sm" />
                        <span>Loading monitored URLs…</span>
                    </p>
                ) : 0 === rows.length ? (
                    <p className="ap-psi-urls__empty">
                        No URLs are monitored yet. Add one above, or import the ones your sitemap lists.
                    </p>
                ) : (
                    <>
                        <Table
                            headers={ headers }
                            rows={ rows }
                            keyBy="url"
                            className="ap-psi-urls__table"
                        />

                        { data?.truncated ? (
                            /*
                                Said out loud. A list that quietly shows a prefix
                                of the monitored set is a list an operator will
                                trust to be all of it.
                            */
                            <TitledAlert
                                color="info"
                                title={ `Showing the first ${ URLS_MAX } URLs` }
                                description={ `This installation monitors ${ total } URLs, and this table lists the first ${ URLS_MAX }. Use the console commands to work with the rest.` }
                                className="mt-4"
                            />
                        ) : null }
                    </>
                ) }
            </Card>
        </div>
    )
}
