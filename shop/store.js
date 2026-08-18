const HWC_CART_KEY='hwc_public_cart_v1';
function hwcCart(){try{const v=JSON.parse(localStorage.getItem(HWC_CART_KEY)||'[]');return Array.isArray(v)?v:[]}catch(e){return[]}}
function hwcSaveCart(v){localStorage.setItem(HWC_CART_KEY,JSON.stringify(v));hwcSyncCartBadge()}
function hwcAdd(productId,qty=1){const cart=hwcCart();const row=cart.find(x=>Number(x.product_id)===Number(productId));if(row)row.qty=Math.min(20,Number(row.qty||0)+qty);else cart.push({product_id:Number(productId),qty:Math.min(20,qty)});hwcSaveCart(cart);return cart}
function hwcSet(productId,qty){let cart=hwcCart();qty=Math.max(0,Math.min(20,Number(qty)||0));const i=cart.findIndex(x=>Number(x.product_id)===Number(productId));if(qty===0&&i>=0)cart.splice(i,1);else if(i>=0)cart[i].qty=qty;else if(qty>0)cart.push({product_id:Number(productId),qty});hwcSaveCart(cart)}
function hwcRemove(productId){hwcSet(productId,0)}
function hwcClear(){hwcSaveCart([])}
function hwcSyncCartBadge(){const n=hwcCart().reduce((a,b)=>a+Number(b.qty||0),0);document.querySelectorAll('[data-cart-count]').forEach(el=>el.textContent=String(n))}
document.addEventListener('DOMContentLoaded',()=>{hwcSyncCartBadge();document.querySelectorAll('[data-add-product]').forEach(btn=>btn.addEventListener('click',()=>{hwcAdd(btn.dataset.addProduct,1);const old=btn.textContent;btn.textContent='Added ✓';setTimeout(()=>btn.textContent=old,800)}))});