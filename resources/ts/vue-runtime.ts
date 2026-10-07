import * as VueRuntime from "vue/dist/vue.esm-bundler";

declare global {
    interface Window {
        RGBFVue: typeof VueRuntime;
    }
}

window.RGBFVue = VueRuntime;
window.ns.vue = VueRuntime;
