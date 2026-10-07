<script>
document.addEventListener('DOMContentLoaded',()=>{
 const priceForOrder=(price,unit)=>{
  if(!unit?.scheduled_price_id)return price;
  const order=POS.order.getValue();
  if(!order.tax_group_id)return price;
  const gross=Number(unit.sale_price_gross);
  const rate=(order.tax_group?.taxes||order.taxes||[]).reduce((sum,t)=>sum+Number(t.rate||0),0);
  return order.tax_type==='exclusive'?Math.round(gross/(1+rate/100)*100000)/100000:gross;
 };
 ['ns-pos-product-sale-price','ns-pos-product-wholesale-price','ns-pos-product-custom-price'].forEach(hook=>nsHooks.addFilter(hook,'rgbf/scheduled-prices',priceForOrder));
 nsHooks.addFilter('ns-pos-product-unit-price','rgbf/scheduled-prices',(price,product)=>priceForOrder(price,product.$quantities?.()));
 nsHooks.addAction('ns-after-product-computed','rgbf/scheduled-prices',(product)=>{
  const unit=product.$quantities?.();
  if(unit?.scheduled_price_id)product.scheduled_price_id=unit.scheduled_price_id;
 });
 nsHooks.addAction('ns-order-before-submit','rgbf/scheduled-prices',(order)=>{
  (order.products||[]).forEach(product=>{const unit=product.$quantities?.();if(unit?.scheduled_price_id)product.scheduled_price_id=unit.scheduled_price_id;});
 });
});
</script>
