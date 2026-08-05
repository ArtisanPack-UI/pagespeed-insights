/**
 * Subscribe a component to the finished-run announcement.
 */

import { onScopeDispose } from 'vue'
import { onPsiResultStored, type PsiResultStored } from '../shared/events'

/**
 * Run `handler` whenever a finished run is announced.
 *
 * The subscription is torn down with the surrounding effect scope, which for a
 * component is its unmount: a listener left behind holds its component's
 * closure alive and refreshes a panel that is no longer on the page.
 */
export function usePsiResultStored( handler: ( event: PsiResultStored ) => void ): void {
    const stop = onPsiResultStored( ( event ) => handler( event ) )

    onScopeDispose( stop )
}
