interface Config {
    visible: string[];
    hidden: string[];
    callbacks: {
        action: string | string[];
        callback: any;
    }[];
}

export class NsHotPress 
{
    private listeners                   =   new Object;

    constructor() {
        document.addEventListener( 'keydown', ( event ) => this.processEvent( event ) );
    }

    /**
     * Whether the keystroke belongs to a field the user is typing into.
     *
     * A shortcut and a text field both want the same keys. With a numpad mounted, every
     * digit typed into a search box was also fed to the numpad, so an amount was being
     * assembled behind the screen the cashier was looking at.
     *
     * Function keys and Escape are let through: those are how the till is driven and no
     * field consumes them. Everything else belongs to whoever has focus, and a component
     * that wants Enter or Backspace inside its own field binds them on the element.
     */
    private isTypingInAField( event ): boolean {
        const element = event.target;

        if ( ! ( element instanceof HTMLElement ) ) {
            return false;
        }

        const editable = element.isContentEditable
            || [ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( element.tagName );

        if ( ! editable ) {
            return false;
        }

        const alwaysAllowed = event.key === 'Escape' || /^F\d{1,2}$/.test( event.key ?? '' );

        return ! alwaysAllowed;
    }

    /**
     * Will process all events and decide
     * to execute according to the configuration provided
     * @param event 
     * @returns void
     */
    processEvent( event ) {
        if ( this.isTypingInAField( event ) ) {
            return;
        }

        for( let index in this.listeners ) {
            /**
             * @param {array} visible
             * @param {array} hidden
             * @param {array} callbacks
             */
            const config: Config   =   this.listeners[ index ].getConfig();

            if ( config.hidden.length > 0 ) {
                const hiddenPassed     =   config.hidden.filter( (element: any) => {
                    return ( element instanceof HTMLElement ) === false && document.querySelector( element ) === null;
                }).length;                
    
                if ( hiddenPassed !== config.hidden.length ) {
                    continue;
                }
            }

            if ( config.visible.length > 0 ) {
                const visiblePassed     =   config.visible.filter( (element: any ) => {
                    return ( element instanceof HTMLElement ) === true || document.querySelector( element ) !== null;
                }).length  === config.visible.length;

                if ( ! visiblePassed ) {
                    continue;
                }
            }
            
            /**
             * Looping all declared variable
             * for a specific callback.
             */
            config.callbacks.forEach( callbackConfig => {
                if ( typeof callbackConfig.action === 'string' ) {
                    this.processSingleAction({
                        event,
                        action: callbackConfig.action.trim(),
                        callback: callbackConfig.callback,
                    });
                } else if ( typeof callbackConfig.action === 'object' && callbackConfig.action !== null && callbackConfig.action.length > 0 ) {
                    callbackConfig.action.forEach( action => {
                        this.processSingleAction({
                            event,
                            action: action.toString().trim(),
                            callback: callbackConfig.callback,
                        });
                    });
                }
            });
        }
    }

    /**
     * The event is threaded through rather than read from the global `window.event`,
     * which is deprecated, absent outside the dispatch of a browser event and empty in a
     * synthetically dispatched one.
     */
    processSingleAction({ event, action, callback }) {
        const combinaisons          =   action.split( '+' );
        const combinableKeys        =   { ctrlKey: false, altKey: false, shiftKey: false };
        
        combinaisons.forEach( combinaison => {
            switch( combinaison ) {
                case 'ctrl': combinableKeys.ctrlKey = true; break;
                case 'alt': combinableKeys.altKey = true; break;
                case 'shift': combinableKeys.shiftKey = true; break;
            }
        });

        const keys                  =   combinaisons.filter( key => ! [ 'ctrl', 'alt', 'shift' ].includes( key ) );

        this.executeCallback({ event, combinableKeys, callback, key: keys[0] });
    }

    /**
     * Execute a call if that match a specific combinaison or not
     * @param {object}  event
     * @param {object}  callback
     * @param {object}  combinableKeys
     * @param {object}  key
     * @return void
     */
    executeCallback({ event, callback, combinableKeys, key }) {
        if ( event.key !== undefined && event.key.toLowerCase() === key.toLowerCase() ) {
            
            let canProceed  =   true;

            for( let index in combinableKeys ) {
                if ( event[ index ] !== combinableKeys[ index ] ) {
                    canProceed  =   false;
                }
            }

            if ( canProceed ) {
                callback( event, key );
            }
        }
    }

    /**
     * Create an instance of HotConfig.
     * @param name identifier
     * @returns HotConfig configuration
     */
    create( name ) {
        return this.listeners[ name ]      =   new HotConfig();
    }

    /**
     * Destroy an instance of HotConfig to
     * avoid further listening
     * @param name identifier
     */
    destroy( name ) {
        delete this.listeners[ name ];
    }
}

class HotConfig {
    private visible: string[]       =   [];
    private hidden: string[]        =   [];
    private callbacks               =   [];

    whenVisible( element ) {
        if ( typeof element === 'object'  ) {
            this.visible.push( ...element );
        } else {
            this.visible.push( element );
        }
        return this;
    }

    clearVisible() {
        this.visible    =   [];
        return this;
    }

    whenNotVisible( element ) {
        if ( element.length > 0 ) {
            this.hidden.push( ...element );
        } else {
            this.hidden.push( element );
        }
        return this;
    }
    
    clearHidden() {
        this.hidden     =   [];
        return this;
    }

    whenPressed( action, callback ) {
        this.callbacks.push({
            action, callback
        });
        return this;
    }

    clearCallbacks() {
        this.callbacks   =   [];
        return this;
    }

    getConfig(): Config {
        return {
            callbacks: this.callbacks,
            hidden: this.hidden,
            visible: this.visible
        };
    }
}