<script>
document.addEventListener('DOMContentLoaded', () => {
    const vue = window.RGBFVue;
    const exchangePopup = vue.markRaw({
        props: ['popup'],
        data: () => ({ points: '', error: '' }),
        mounted() { this.popupCloser(); },
        methods: {
            popupCloser, popupResolver,
            confirm() {
                const data = this.popup.params.data;
                const points = Number(this.points);
                const discount = Math.round(points / data.redeem_points * data.redeem_baht * 100) / 100;
                if (!Number.isSafeInteger(points) || points < 1 || points > data.points || points % data.redeem_points || discount > this.popup.params.subtotal) {
                    this.error = 'คะแนนต้องเป็นจำนวนเท่าของ ' + data.redeem_points + ' และไม่เกินคะแนนคงเหลือหรือยอดสินค้า'; return;
                }
                this.popupResolver({points, discount});
            }
        },
        render() {
            const h = vue.h, data = this.popup.params.data;
            return h('form', {class:'ns-box p-6 text-fontcolor', style:'width:420px;max-width:90vw', role:'dialog', 'aria-label':'แลกคะแนนเป็นส่วนลด', onSubmit:e=>{e.preventDefault();this.confirm();}}, [
                h('h2', {class:'text-xl font-bold'}, 'แลกคะแนนเป็นส่วนลด'),
                h('p', 'คะแนนรวมทุกสาขาคงเหลือ ' + data.points),
                h('p', data.redeem_points + ' คะแนน = ' + data.redeem_baht + ' บาท'),
                h('label', {class:'block my-4'}, ['จำนวนคะแนนที่ต้องการใช้', h('input', {type:'number', min:data.redeem_points, step:data.redeem_points, max:data.points, required:true, class:'border p-2 w-full', value:this.points, onInput:e=>this.points=e.target.value})]),
                h('p', 'คะแนนจะหักเมื่อชำระบิลสำเร็จ'),
                this.error ? h('p', {role:'alert'}, this.error) : null,
                h('button', {type:'submit', class:'border p-3'}, 'ยืนยันแลกส่วนลด'),
                h('button', {type:'button', class:'border p-3', onClick:()=>this.popupResolver(false)}, 'ยกเลิก')
            ]);
        }
    });
    const clear = () => {
        const order = POS.order.getValue();
        if (order.loyalty_points) {
            order.discount = 0;
            order.discount_percentage = 0;
            order.loyalty_points = 0;
            order.loyalty_request_key = null;
            POS.order.next(order);
            nsHooks.doAction('ns-after-cart-changed');
        }
    };
    const button = vue.markRaw({
        props: ['order', 'settings', 'options'],
        data: () => ({loading:false, message:'', balance:null, balanceMessage:'เลือกสมาชิกเพื่อดูคะแนน', customerKey:null, requestVersion:0, subscription:null}),
        mounted() { window.addEventListener("rgbf-loyalty-refresh", this.refreshBalance); this.subscription = POS.order.subscribe(order => { const key = order?.customer_id || null; if(key !== this.customerKey) { this.customerKey=key; this.refreshBalance(); } }); },
        beforeUnmount() { window.removeEventListener("rgbf-loyalty-refresh", this.refreshBalance); this.requestVersion++; this.subscription?.unsubscribe(); },
        render() { return vue.h('span', {style:'display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap'}, [vue.h('strong', {role:'status', style:'color:#173c40'}, this.balanceMessage), vue.h('button', {type:'button', class:'border p-2 text-fontcolor', onClick:this.refreshBalance}, 'อัปเดตคะแนน'), @json(auth()->user()->can('nexopos.pos.cart-discount')) ? vue.h('button', { type:'button', disabled:this.loading, class:'border p-2 text-fontcolor', onKeydown:e=>e.stopPropagation(), onClick: this.exchange }, this.loading ? 'กำลังตรวจคะแนน…' : (this.order?.loyalty_points ? 'ยกเลิกแลกคะแนน (' + this.order.loyalty_points + ')' : 'แลกคะแนน')) : null, this.message ? vue.h('span',{role:'alert'},this.message) : null]); },
        methods: {
          async refreshBalance() {
            const customer = POS.order.getValue()?.customer_id;
            const version = ++this.requestVersion;
            this.balance=null;
            if(!customer) { this.balanceMessage='เลือกสมาชิกเพื่อดูคะแนน'; return; }
            this.balanceMessage='กำลังตรวจคะแนน…';
            try {
              const data = await new Promise((resolve,reject)=>nsHttpClient.get('/dashboard/customers/branch-points/context?customer_id='+encodeURIComponent(customer)).subscribe({next:resolve,error:reject}));
              if(version !== this.requestVersion || POS.order.getValue()?.customer_id !== customer) return;
              this.balance=data.points;
              this.balanceMessage=data.member ? 'คะแนนคงเหลือรวมทุกสาขา: '+Number(data.points).toLocaleString('th-TH')+' คะแนน'+(data.enabled ? '' : ' (ปิดสะสม/แลกคะแนน)') : 'ลูกค้าขาจรหรือยังไม่มีเบอร์โทรสมาชิก';
            } catch(e) { if(version === this.requestVersion) this.balanceMessage='ตรวจคะแนนไม่สำเร็จ กดอัปเดตคะแนนเพื่อลองใหม่'; }
          },
          async exchange() {
            this.message = '';
            const order = POS.order.getValue();
            if (order.loyalty_points) { clear(); return; }
            if (!order.customer_id) { this.message = 'เลือกสมาชิกก่อนแลกคะแนน'; return; }
            if (order.id || order.discount > 0 || order.coupons?.length) { this.message = 'ใช้กับบิลใหม่ที่ไม่มีส่วนลดท้ายบิลหรือคูปอง กรุณานำส่วนลดเดิมออกก่อน'; return; }
            try {
                this.loading = true;
                const data = await new Promise((resolve, reject) => nsHttpClient.get('/dashboard/customers/branch-points/context?customer_id=' + encodeURIComponent(order.customer_id)).subscribe({next:resolve,error:reject}));
                if (!data.enabled || !data.member) { this.message = 'กรุณาเลือกสมาชิกที่มีเบอร์โทร (ไม่ใช่ลูกค้าขาจร) และให้ผู้ดูแลเปิดระบบคะแนนร่วมทุกสาขา'; return; }
                const {points, discount} = await new Promise((resolve,reject)=>Popup.show(exchangePopup,{data,subtotal:order.subtotal,resolve,reject},{closeOnOverlayClick:false}));
                if (POS.order.getValue() !== order) { this.message = 'บิลเปลี่ยนแล้ว กรุณาแลกคะแนนใหม่'; return; }
                order.loyalty_points = points;
                order.loyalty_request_key = crypto.randomUUID();
                order.loyalty_customer_id = order.customer_id;
                order.discount_type = 'flat'; order.discount = discount; order.discount_percentage = 0;
                POS.order.next(order); nsHooks.doAction('ns-after-cart-changed');
            } catch (error) { if(error !== false) this.message = error?.message || 'ตรวจคะแนนไม่สำเร็จ กรุณาลองใหม่'; }
            finally { this.loading = false; }
        } }
    });
    nsHooks.addAction('ns-after-cart-reset', 'rgbf/loyalty-button', () => POS.cartHeaderButtons.next({...POS.cartHeaderButtons.getValue(), BranchLoyalty: button}), 30);
    nsHooks.addAction('ns-after-cart-reset', 'rgbf/loyalty-balance', () => window.dispatchEvent(new Event('rgbf-loyalty-refresh')), 40);
    nsHooks.addAction('ns-after-cart-changed', 'rgbf/loyalty-customer', () => {
        const order = POS.order.getValue();
        if (order.loyalty_points && order.loyalty_customer_id !== order.customer_id) clear();
    }, 5);
});
</script>

