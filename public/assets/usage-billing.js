'use strict';
document.querySelectorAll('[data-usage-form]').forEach(form=>{
  const heads=form.querySelector('[data-pricing-heads]');
  function revision(){if(!heads)return;const [plan,version]=form.elements['data[choice]'].value.split(':'),cycle=form.elements['data[cycle]'].value;const row=JSON.parse(heads.dataset.pricingHeads).find(x=>x.plan_id===plan&&String(x.plan_version)===version&&x.cycle===cycle);form.elements['data[expected]'].value=row?row.version:0;}
  if(heads){form.elements['data[choice]'].addEventListener('change',revision);form.elements['data[cycle]'].addEventListener('change',revision);revision();}
  form.addEventListener('submit',async event=>{event.preventDefault();if(form.dataset.busy==='1'||!form.reportValidity())return;const data=new FormData(form),button=form.querySelector('button'),error=form.querySelector('[data-usage-error]');form.dataset.busy='1';button.disabled=true;error.hidden=true;
    try{const response=await fetch('/merchant.php',{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});const r=await response.json();if(!response.ok||!r.ok)throw new Error('Change was not saved. Check permission, pricing, scope and current revision, then reload before retrying.');location.reload();}
    catch(e){error.textContent=e.message;error.hidden=false;button.disabled=false;form.dataset.busy='0';}
  });
});
