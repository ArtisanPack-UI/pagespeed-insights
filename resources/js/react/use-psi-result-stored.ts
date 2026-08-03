/**
 * Subscribe a component to the finished-run announcement.
 */

import { useEffect, useRef } from 'react'
import { onPsiResultStored, type PsiResultStored } from '../shared/events'

/**
 * Run `handler` whenever a finished run is announced.
 *
 * The handler is held in a ref so a caller need not memoise it: a subscription
 * that tore down and rebuilt on every render would drop announcements that
 * landed in between, which is exactly the announcement this exists to catch.
 */
export function usePsiResultStored( handler: ( event: PsiResultStored ) => void ): void {
    const handlerRef = useRef( handler )

    handlerRef.current = handler

    useEffect( () => onPsiResultStored( ( event ) => handlerRef.current( event ) ), [] )
}
