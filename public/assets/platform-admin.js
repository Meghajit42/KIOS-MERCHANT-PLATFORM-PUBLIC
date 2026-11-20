'use strict';
document.addEventListener('DOMContentLoaded',()=>{
  for(const form of document.querySelectorAll('[data-pa-form]')){
    form.addEventListener('submit',event=>{
      if(form.dataset.busy==='1'){event.preventDefault();return;}
      form.dataset.busy='1';form.setAttribute('aria-busy','true');
      const button=form.querySelector('button');if(button){button.disabled=true;button.textContent='Saving securely…';}
    });
  }
});
