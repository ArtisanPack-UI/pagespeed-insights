/**
 * The title-and-description alert every card in this package uses.
 */

import type { ReactNode } from 'react'
import { Alert } from '@artisanpack-ui/react/feedback'

/**
 * Props for {@link TitledAlert}.
 */
export interface TitledAlertProps {
    /** The headline. */
    title: ReactNode
    /** The explanation below it. */
    description?: ReactNode
    /** DaisyUI colour variant. */
    color?: 'info' | 'success' | 'warning' | 'error'
    /** Additional classes on the alert. */
    className?: string
}

/**
 * An alert carrying both a headline and an explanation.
 *
 * The library's `Alert` takes children rather than a title and a description,
 * and every state message in this package is both, so the pairing is written
 * once here instead of five times.
 */
export function TitledAlert( props: TitledAlertProps ) {
    const { title, description, color = 'info', className } = props

    return (
        <Alert color={ color } className={ className }>
            <div>
                <p className="font-medium">{ title }</p>
                { description ? <p className="text-sm opacity-80">{ description }</p> : null }
            </div>
        </Alert>
    )
}
