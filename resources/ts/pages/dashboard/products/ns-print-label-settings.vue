<script>
export default defineComponent({
    name: 'ns-print-label-settings',
    props: [ 'popup' ],
    template: `
    <div>
        <div class="shadow-lg ns-box w-95vw md:w-[40vw]">
            <div class="border-b ns-box-body p-2 flex justify-between items-center">
                <h3>{{ __( 'ตั้งค่าฉลากของสินค้านี้' ) }}</h3>
                <div>
                    <ns-close-button  @click="closePopup()"></ns-close-button>
                </div>
            </div>
            <div class="p-2">
                <ns-field :field="field" :key="index" :ref="field.name" v-for="(field, index) of fields"></ns-field>
            </div>
            <div class="border-t ns-box-footer p-2 flex justify-between">
                <div></div>
                <div>
                    <ns-button type="info" @click="saveSettings()">{{ __( 'บันทึก' ) }}</ns-button>
                </div>
            </div>
        </div>
    </div>
    `,
    data() {
        return {
            fields: [],
            validation: new FormValidation,
        }
    },
    methods: {
        __,
        saveSettings() {
            this.popup.close();
            const form  =   this.validation.extractFields( this.fields );
            this.popup.params.resolve( form )
        },
        closePopup() {
            this.popup.close();
            this.popup.params.reject( false );
        }
    },
    mounted() {
        const product       =   this.popup.params.product;

        /**
         * The second field that used to sit here was a copy of this one: labelled "Unit" as well,
         * named procurement_id, described as "Choose quantity from procurement", and filled with the
         * same unit options. Nothing ever read it — saveSettings() returns the form and the caller
         * takes selectedUnitQuantity and times only — so it was two identical dropdowns where one
         * mattered and no way to tell which. Removed.
         */
        this.fields         =   this.validation.createFields([
            {
                label: __( 'หน่วยที่จะพิมพ์' ),
                type: 'select',
                name: 'selectedUnitQuantity',
                description: __( 'ราคาและบาร์โค้ดบนฉลากจะมาจากหน่วยนี้' ),
                options: product.unit_quantities.map( unit_quantity => {
                    return {
                        label: unit_quantity.unit.name,
                        value: unit_quantity
                    }
                }),
                value: product.selectedUnitQuantity || product.unit_quantities[0]
            }, {
                label: __( 'จำนวนดวง' ),
                type: 'number',
                name: 'times',
                description: __( 'จะพิมพ์ฉลากของสินค้านี้กี่ดวง' ),
                value: product.times || 1
            }
        ]);

        /**
         * we want that the quantity field always select
         * the entire quantity
         */
        setTimeout(() => {
            this.$refs.times[0].$el.parentNode.querySelector( 'input' ).addEventListener( 'focus', function( ) {
                this.select();
            });
        }, 100 );
    }
});
</script>