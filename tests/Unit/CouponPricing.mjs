import assert from 'node:assert/strict';
import { test } from 'node:test';
import { couponValue } from '../../resources/ts/libraries/coupon-pricing.ts';
const lines = [{product_id:1,unit_id:1,quantity:3,total_price:180},{product_id:2,unit_id:1,quantity:2,total_price:80}];
const bundle = {type:'bundle_price',discount_value:80,bundle_items:[{product_id:1,unit_id:1,quantity:1},{product_id:2,unit_id:1,quantity:1}]};
test('two completed bundles with an extra item give exactly 40 discount',()=>assert.equal(couponValue(bundle,lines,260),40));
test('wrong selling unit does not qualify',()=>assert.equal(couponValue(bundle,[{...lines[0],unit_id:2},lines[1]],260),0));
test('incomplete set does not qualify',()=>assert.equal(couponValue(bundle,[lines[0]],180),0));
test('bundle never increases regular price',()=>assert.equal(couponValue({...bundle,discount_value:200},lines,260),0));
test('flat discount cannot exceed subtotal',()=>assert.equal(couponValue({type:'flat_discount',discount_value:500},lines,260),260));
test('percentage uses subtotal and rounds to satang',()=>assert.equal(couponValue({type:'percentage_discount',discount_value:7},lines,99.99),7));
test('minimum purchase is respected',()=>assert.equal(couponValue({...bundle,minimum_cart_value:300},lines,260),0));
test('maximum purchase is respected',()=>assert.equal(couponValue({...bundle,maximum_cart_value:200},lines,260),0));
test('API bundle rules match cart bundle items',()=>assert.equal(couponValue({...bundle,bundle_rules:bundle.bundle_items},lines,260),40));

test('restored POS search cart resolves unit from selected quantity',()=>assert.equal(couponValue(bundle,[{...lines[0],unit_id:0,$quantities:()=>({unit_id:1})},lines[1]],260),40));
