/** Mirrors server coupon pricing; checkout revalidates every value. */
export function couponValue(coupon: any, products: any[], subtotal: number): number {
    if (subtotal < Number(coupon.minimum_cart_value || 0) || (Number(coupon.maximum_cart_value) > 0 && subtotal > Number(coupon.maximum_cart_value))) return 0;
    let value = 0;
    if (coupon.type === 'bundle_price') {
        const rules = coupon.bundle_rules || coupon.bundle_items || [];
        if (!rules.length) return 0;
        const buckets = rules.map((rule: any) => ({rule, rows: products.filter(p => Number(p.product_id) === Number(rule.product_id) && Number(p.unit_id || p.$quantities?.()?.unit_id) === Number(rule.unit_id) && Number(p.quantity) > 0)}));
        const sets = Math.min(...buckets.map(({rule, rows}) => Math.floor((rows.reduce((n, p) => n + Number(p.quantity), 0) + 0.000001) / Number(rule.quantity))));
        if (sets < 1) return 0;
        let eligible = 0;
        buckets.forEach(({rule, rows}) => {
            let needed = sets * Number(rule.quantity);
            [...rows].sort((a,b) => Number(a.total_price) / Number(a.quantity) - Number(b.total_price) / Number(b.quantity)).forEach(p => {
                const take = Math.min(needed, Number(p.quantity));
                eligible += take * Number(p.total_price) / Number(p.quantity);
                needed -= take;
            });
        });
        value = eligible - sets * Number(coupon.discount_value);
    } else {
        value = coupon.type === 'percentage_discount' ? subtotal * Number(coupon.discount_value) / 100 : Number(coupon.discount_value);
    }
    return Math.round((Math.max(0, Math.min(subtotal, value)) + Number.EPSILON) * 100) / 100;
}
