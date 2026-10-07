import { Page } from '@playwright/test';

/**
 * Contrast helpers for catching text that is unreadable against the surface it
 * sits on, which happens when a theme flips its font colour but a hardcoded
 * background stays put.
 */

export type ContrastReading = {
    selector: string;
    text: string;
    color: string;
    background: string;
    ratio: number;
};

/**
 * Measures the contrast ratio of the first element matching each selector.
 *
 * The background is resolved by compositing the element's own background over
 * the backgrounds of its ancestors, which is what the eye actually sees when a
 * translucent band covers an image or a page surface.
 *
 * Colours are resolved by painting them on a canvas, so any notation the
 * browser understands works, including the oklch() values Tailwind emits.
 */
export async function measureContrast( page: Page, selectors: string[] ): Promise<ContrastReading[]> {
    return await page.evaluate( ( selectors: string[] ) => {
        const canvas = document.createElement( 'canvas' );
        canvas.width = 1;
        canvas.height = 1;

        const context = canvas.getContext( '2d' )!;

        /**
         * Paints a CSS colour on a single pixel and reads it back, which
         * converts any notation into plain sRGB with its alpha.
         */
        const toRgba = ( value: string ): number[] | null => {
            if ( value === '' || value === 'none' ) {
                return null;
            }

            context.clearRect( 0, 0, 1, 1 );
            context.fillStyle = '#000000';
            context.fillStyle = value;
            context.fillRect( 0, 0, 1, 1 );

            const data = context.getImageData( 0, 0, 1, 1 ).data;

            return [ data[ 0 ], data[ 1 ], data[ 2 ], data[ 3 ] / 255 ];
        };

        const composite = ( top: number[], bottom: number[] ): number[] => {
            const alpha = top[ 3 ];

            return [
                top[ 0 ] * alpha + bottom[ 0 ] * ( 1 - alpha ),
                top[ 1 ] * alpha + bottom[ 1 ] * ( 1 - alpha ),
                top[ 2 ] * alpha + bottom[ 2 ] * ( 1 - alpha ),
                1,
            ];
        };

        const luminance = ( rgb: number[] ): number => {
            const channels = rgb.slice( 0, 3 ).map( channel => {
                const ratio = channel / 255;

                return ratio <= 0.03928 ? ratio / 12.92 : Math.pow( ( ratio + 0.055 ) / 1.055, 2.4 );
            } );

            return 0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ];
        };

        /**
         * Stacks every background up the tree until an opaque one is found, so
         * translucent layers are accounted for.
         */
        const resolveBackground = ( element: Element ): number[] => {
            const layers: number[][] = [];
            let current: Element | null = element;

            while ( current !== null ) {
                const parsed = toRgba( window.getComputedStyle( current ).backgroundColor );

                if ( parsed !== null && parsed[ 3 ] > 0 ) {
                    layers.push( parsed );

                    if ( parsed[ 3 ] === 1 ) {
                        break;
                    }
                }

                current = current.parentElement;
            }

            // Anything still unresolved sits on the canvas, which is white.
            let result = [ 255, 255, 255, 1 ];

            for ( let index = layers.length - 1; index >= 0; index-- ) {
                result = composite( layers[ index ], result );
            }

            return result;
        };

        const describe = ( rgb: number[] ): string =>
            `rgb(${Math.round( rgb[ 0 ] )},${Math.round( rgb[ 1 ] )},${Math.round( rgb[ 2 ] )})`;

        return selectors.map( selector => {
            const element = document.querySelector( selector );

            if ( element === null ) {
                return { selector, text: '', color: '', background: '', ratio: -1 };
            }

            const color = toRgba( window.getComputedStyle( element ).color );
            const background = resolveBackground( element );

            if ( color === null ) {
                return { selector, text: '', color: '', background: describe( background ), ratio: -1 };
            }

            const foreground = composite( color, background );

            const brightest = Math.max( luminance( foreground ), luminance( background ) );
            const darkest = Math.min( luminance( foreground ), luminance( background ) );

            return {
                selector,
                text: element.textContent?.trim().slice( 0, 40 ) ?? '',
                color: describe( foreground ),
                background: describe( background ),
                ratio: Math.round( ( ( brightest + 0.05 ) / ( darkest + 0.05 ) ) * 100 ) / 100,
            };
        } );
    }, selectors );
}

/**
 * Reports the theme the page was rendered with. Only one theme stylesheet is
 * served per request, so a theme cannot be swapped client side.
 *
 * The dashboard layout exposes it through data-theme while the POS layout puts
 * it on the html class, so both are inspected.
 */
export async function getActiveTheme( page: Page ): Promise<string | null> {
    return await page.evaluate( () => {
        const root = document.documentElement;
        const known = [ 'light', 'dark', 'phosphor' ];

        return root.getAttribute( 'data-theme' )
            ?? known.find( theme => root.classList.contains( theme ) )
            ?? null;
    } );
}
