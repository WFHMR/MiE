<script>
import FormValidation from '../../libraries/form-validation';
import { nsSnackBar, nsHttpClient } from '../../bootstrap';
import { __ } from '~/libraries/lang';
import popupResolver from '~/libraries/popup-resolver';
export default { 
    name: 'ns-create-coupons',
    mounted() {
        this.loadForm();
    },
    computed: {
        isBundle() { return this.form.tabs?.general?.fields.find(f => f.name === 'type')?.value === 'bundle_price'; },
        productField() { return this.form.tabs?.selected_products?.fields.find(f => f.name === 'products'); },
        validTabs() {
            if ( this.form ) {
                const tabs  =   [];
                for( let tab in this.form.tabs ) {
                    if ([ 'selected_products', 'selected_categories', 'selected_groups', 'selected_customers' ].includes( tab ) ) {
                        tabs.push( this.form.tabs[ tab ] );
                    }
                }
                return tabs;
            }
            return [];
        },
        activeValidTab() {
            return this.validTabs.filter( tab => tab.active )[0];
        },
        generalTab() {
            const tabs  =   [];
            for( let tab in this.form.tabs ) {
                if ([ 'general' ].includes( tab ) ) {
                    tabs.push( this.form.tabs[ tab ] );
                }
            }
            return tabs;
        }
    },
    data() {
        return {
            formValidation: new FormValidation,
            form: {},
            labels: {},
            nsSnackBar,
            nsHttpClient,
            productSearch: '', productResults: [], productLoading: false, productError: '', bundleItems: [], productNames: {},
        }
    },
    props: [ 'submitMethod', 'submitUrl', 'returnUrl', 'src', 'rules', 'popup' ],
    methods: {
        __,
        popupResolver,
        setTabActive( tab ) {
            this.validTabs.forEach( tab => tab.active = false );
            tab.active  =   true;
        },
        submit() {
            if ( this.formValidation.validateForm( this.form ).length > 0 ) {
                return nsSnackBar.error( __( 'Unable to proceed the form is not valid.'), __( 'Okay' ) );
            }

            if ( this.submitUrl === undefined ) {
                return nsSnackBar.error( __( 'No submit URL was provided' ), __( 'Okay' ) );
            }

            this.formValidation.disableForm( this.form );

            this.form.tabs.general.fields.find(f => f.name === 'bundle_items').value = JSON.stringify(this.isBundle ? this.bundleItems : []);
            if (this.isBundle) {
                this.productField.value = [];
                this.form.tabs.selected_categories.fields[0].value = [];
            }
            const data  =   {
                ...this.formValidation.extractForm( this.form ),
            }

            nsHttpClient[ this.submitMethod ? this.submitMethod.toLowerCase() : 'post' ]( this.submitUrl, data )
                .subscribe( data => {
                    if ( data.status === 'success' ) {
                        if ( this.popup ) {
                            this.popupResolver( data );
                        } else {
                            return document.location   =   this.returnUrl;
                        }
                    }
                    this.formValidation.enableForm( this.form );
                }, ( error ) => {
                    nsSnackBar.error( error.message || __( 'An unexpected error occurred.' ), undefined, {
                        duration: 5000
                    });

                    if ( error.status === 'error' ) {
                        this.formValidation.triggerError( this.form, error.data );
                    }

                    this.formValidation.enableForm( this.form );
                })
        },
        handleGlobalChange( event ) {
            this.globallyChecked    =   event;
            this.rows.forEach( r => r.$checked = event );
        },
        searchProducts() {
            this.productLoading = true; this.productError = '';
            nsHttpClient.get('/api/promotions/products?q=' + encodeURIComponent(this.productSearch)).subscribe({
                next: items => { this.productResults = items; this.productLoading = false; items.forEach(p => this.productNames[p.id] = p); },
                error: e => { this.productLoading = false; this.productError = e.message || 'ค้นหาสินค้าไม่สำเร็จ'; }
            });
        },
        addProduct(product, unit) {
            if (this.isBundle) {
                if (this.bundleItems.some(i => i.product_id === product.id && i.unit_id === unit.unit_id)) return;
                this.bundleItems.push({product_id: product.id, unit_id: unit.unit_id, quantity: 1});
            } else {
                if (!this.productField.options.some(o => o.value === product.id)) this.productField.options.push({value: product.id, label: product.name});
                if (!this.productField.value.includes(product.id)) this.productField.value.push(product.id);
            }
        },
        itemName(item) { const p = this.productNames[item.product_id]; return (p?.name || 'สินค้า #' + item.product_id) + ' — ' + (p?.units.find(u => Number(u.unit_id) === Number(item.unit_id))?.name || 'หน่วย #' + item.unit_id); },
        loadForm() {
            const request   =   nsHttpClient.get( `${this.src}` );
            request.subscribe( f => {
                this.labels     =   f.labels;
                this.form    =   this.parseForm( f.form );
                this.bundleItems = JSON.parse(this.form.tabs.general.fields.find(f => f.name === 'bundle_items').value || '[]');
                const ids = [...new Set(this.bundleItems.map(i => i.product_id))];
                if (ids.length) nsHttpClient.get('/api/promotions/products?' + ids.map(id => 'ids[]=' + id).join('&')).subscribe(items => items.forEach(p => this.productNames[p.id] = p));
            }, e => { this.productError = e.message || 'โหลดแบบฟอร์มไม่สำเร็จ'; nsSnackBar.error(this.productError); });
        },
        parseForm( form ) {
            form.main.value     =   form.main.value === undefined ? '' : form.main.value;
            form.main           =   this.formValidation.createFields([ form.main ])[0];
            let index           =   0;

            for( let key in form.tabs ) {
                if ( index === 1 && form.tabs[ key ].active === undefined ) {
                    form.tabs[ key ].active  =   true;
                }

                form.tabs[ key ].active     =   form.tabs[ key ].active === undefined ? false : form.tabs[ key ].active;
                form.tabs[ key ].fields     =   this.formValidation.createFields( form.tabs[ key ].fields );

                index++;
            }

            return form;
        },
        addOption( option ) {
            const index     =   this.options.indexOf( option );

            if ( index >= 0 ) {
                this.options[ index ].selected  =   !this.options[ index ].selected;
            }
        },
        removeOption({ option, index }) {
            option.selected     =   false;
        },
        getRuleForm() {
            return this.form.ruleForm;
        },
        addRule() {
            this.form.rules.push( this.getRuleForm() );
        },
        removeRule( index ) {
            this.form.rules.splice( index, 1 );
        }
    }
}
</script>
<template>
    <div class="form flex-auto flex flex-col" id="crud-form">
        <div v-if="Object.values( form ).length === 0" class="flex items-center justify-center flex-auto">
            <ns-spinner/>
        </div>
        <template v-if="Object.values( form ).length > 0">
            <div :class="popup ? 'bg-box-background w-95vw md:w-[66.67vw] h-[80vh] overflow-y-auto p-4' : ''">
                <div class="flex flex-col">
                    <div class="flex justify-between items-center">
                        <label for="title" class="font-bold my-2 text-font">{{ submitMethod.toLowerCase() === 'post' ? labels.create_title : labels.edit_title }}</label>
                        <div for="title" class="text-sm my-2" v-if="!popup">
                            <a v-if="returnUrl" :href="returnUrl" class="rounded-full border ns-inset-button error px-2 py-1">{{ __( 'Return' ) }}</a>
                        </div>
                    </div>
                    <div :class="form.main.disabled ? 'disabled' : ( form.main.errors.length > 0 ? 'error' : 'info' )" class="input-group flex border-2 rounded overflow-hidden">
                        <input v-model="form.main.value"
                            @blur="formValidation.checkField( form.main )"
                            @change="formValidation.checkField( form.main )"
                            :disabled="form.main.disabled"
                            type="text"
                            class="flex-auto text-fontcolor outline-hidden h-10 px-2">
                        <button :disabled="form.main.disabled" @click="submit()" class="outline-hidden px-4 h-10"><slot name="save">{{ __( 'Save' ) }}</slot></button>
                    </div>
                    <p class="text-xs text-fontcolor py-1" v-if="form.main.description && form.main.errors.length === 0">{{ form.main.description }}</p>
                    <p class="text-xs py-1 text-error-tertiary" v-bind:key="index" v-for="(error, index) of form.main.errors">
                        <span><slot name="error-required">{{ error.identifier }}</slot></span>
                    </p>
                </div>
                <div id="form-container" class="-mx-4 flex flex-wrap mt-4">
                    <div class="px-4 w-full md:w-1/2">
                        <div class="rounded ns-box shadow p-2" v-bind:key="index" v-for="( tab, index) of generalTab">
                            <template v-for="field in tab.fields" :key="field.name">
                                <label v-if="field.name === 'type'" class="block mb-3">ประเภทโปร
                                    <select v-model="field.value" class="block border p-2 w-full" aria-label="ประเภทโปร">
                                        <option value="">เลือกประเภทโปร</option><option v-for="o in field.options" :value="o.value" :key="o.value">{{ o.label }}</option>
                                    </select>
                                </label>
                                <label v-else-if="field.type === 'datetimepicker'" class="block mb-3">{{ field.label }} (เว้นว่าง = ไม่จำกัด)
                                    <input type="datetime-local" :aria-label="field.label" :value="String(field.value || '').replace(' ', 'T').slice(0,16)" @input="field.value = $event.target.value || null" class="block border p-2 w-full">
                                </label>
                                <ns-field v-else :field="field"></ns-field>
                            </template>
                        </div>
                    </div>
                    <div class="px-4 w-full md:w-1/2">
                        <div class="ns-box rounded p-3 mb-4">
                            <h3 class="font-bold">{{ isBundle ? 'สินค้าและจำนวนต่อชุด' : 'ค้นหาสินค้าที่เป็นเงื่อนไขของโปร' }}</h3>
                            <p class="text-sm my-2">{{ isBundle ? 'เลือกสินค้าและหน่วยขายให้ครบ ระบุจำนวนต่อชุด ราคาด้านซ้ายคือราคาของทั้งชุด ระบบคิดซ้ำตามจำนวนชุดที่ครบ' : 'หากเป็นโปรทั้งร้าน ไม่ต้องเลือกสินค้าและหมวดหมู่ ส่วนลดคิดจากยอดสินค้าทั้งบิล' }}</p>
                            <div class="flex border input-group info"><input aria-label="ค้นหาสินค้าโปรโมชั่น" v-model="productSearch" @keydown.enter.prevent="searchProducts()" class="min-w-0 flex-auto p-2" placeholder="ชื่อ / SKU / บาร์โค้ด"><button type="button" @click="searchProducts()" class="p-2">ค้นหา</button></div>
                            <p v-if="productLoading">กำลังค้นหา...</p><p v-if="productError" class="text-error-tertiary">{{ productError }}</p>
                            <div class="max-h-64 overflow-y-auto">
                                <div v-for="p in productResults" :key="p.id" class="border-b py-2">
                                    <strong>{{ p.name }}</strong><span class="text-sm"> · {{ p.sku }}</span>
                                    <div v-if="isBundle" class="flex flex-wrap gap-2"><button v-for="u in p.units" :key="u.unit_id" type="button" @click="addProduct(p,u)" class="border rounded p-2">เพิ่ม {{ u.name }} · {{ u.price }} บาท</button><span v-if="!p.units.length">ไม่มีหน่วยขายในสาขาที่เลือก</span></div>
                                    <button v-else type="button" @click="addProduct(p,null)" class="border rounded p-2">เลือกสินค้านี้</button>
                                </div>
                            </div>
                            <div v-if="isBundle" class="mt-3">
                                <div v-for="(item,index) in bundleItems" :key="item.product_id + ':' + item.unit_id" class="border p-2 my-2">
                                    <div>{{ itemName(item) }}</div><label>จำนวนต่อชุด <input type="number" min="1" step="1" v-model.number="item.quantity" class="border w-24 p-1"></label><button type="button" @click="bundleItems.splice(index,1)" class="p-2 text-error-tertiary">ลบ</button>
                                </div>
                                <p v-if="!bundleItems.length">ยังไม่ได้เลือกสินค้าในชุด</p>
                            </div>
                        </div>
                        <div id="tabbed-card">
                            <div id="card-header" class="flex ml-4 flex-wrap ns-tab">
                                <div @click="setTabActive( tab )" :class="tab.active ? 'active' : 'inactive'" v-for="( tab, index ) of validTabs" v-bind:key="index" class="tab cursor-pointer px-4 py-2 rounded-tl-lg rounded-tr-lg">
                                    {{ tab.label }}
                                </div>
                            </div>
                            <div class="ns-tab-item">
                                <div class="shadow p-2 rounded">
                                    <div class="flex flex-col" v-bind:key="index" v-for="( field, index ) of activeValidTab.fields">
                                        <ns-field v-if="!isBundle || !['products','categories'].includes(field.name)" :field="field"></ns-field>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
