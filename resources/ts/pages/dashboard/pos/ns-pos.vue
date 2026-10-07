<template>
    <div class="h-full flex-auto flex flex-col" id="pos-container">
        <ns-pos-shortcut-bar></ns-pos-shortcut-bar>
        <div class="flex overflow-hidden flex-shrink-0 px-2 pt-2">
            <div class="-mx-2 flex overflow-x-auto pb-1">
                <div class="header-buttons flex px-2 flex-shrink-0" :key="index" v-for="(component,index) of buttons">
                    <component :is="component"></component>
                </div>
            </div>
        </div>
        <div class="flex-auto overflow-hidden flex p-2">
            <div class="flex flex-auto overflow-hidden -m-2">
                <!--
                    Single column layout: the product grid was removed because this
                    store sells with a barcode scanner. The barcode input used to live
                    inside the grid, so it is now rendered here as its own bar.
                -->
                <div class="w-full flex flex-col overflow-hidden p-2">
                    <ns-pos-barcode-bar></ns-pos-barcode-bar>
                    <div class="flex flex-auto overflow-hidden">
                        <ns-pos-cart></ns-pos-cart>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import nsPosCart from './ns-pos-cart.vue';
import nsPosBarcodeBar from './ns-pos-barcode-bar.vue';
import nsPosShortcutBar from './ns-pos-shortcut-bar.vue';

export default {
    name: 'ns-pos',
    computed: {
        buttons() {
            return POS.header.buttons;
        }
    },
    mounted() {
        this.visibleSectionSubscriber   =   POS.visibleSection.subscribe( section => {
            this.visibleSection    =   section;
        });

        /**
         * Assuming all vue component
         * has been loaded
         */
        const loader    =   document.getElementById( 'loader' );
        loader.classList.remove( 'fade-in-entrance' );
        loader.classList.add( 'fade-out-exit' );
        
        setTimeout( () => {
            loader.remove();
            POS.reset();
        }, 500 ); 
        
    },
    unmounted() {
        this.visibleSectionSubscriber.unsubscribe();
    },
    data() {
        return {
            visibleSection: null,
            visibleSectionSubscriber: null,
        }
    },
    components: {
        nsPosCart,
        nsPosBarcodeBar,
        nsPosShortcutBar,
    }
}
</script>