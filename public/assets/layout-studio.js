'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const form=document.querySelector('#layout-form');if(!form)return;
 const status=document.querySelector('#draft-status');
 const preview=document.querySelector('.layout-preview');
 const dirty=()=>{status.textContent='Unsaved changes';
  for(const k of ['cards','navigation','logo','density']){for(const cls of [...preview.classList])if(cls.startsWith(k+'-'))preview.classList.remove(cls);preview.classList.add(k+'-'+form.elements.namedItem(k).value);}
  const template=form.elements.namedItem('presentation[template]').value;preview.classList.remove('template-modern','template-counter');preview.classList.add('template-'+template);
  preview.style.fontFamily=form.elements.namedItem('presentation[font]').value==='serif'?'Georgia,serif':'system-ui,sans-serif';
  for(const k of ['primary','secondary','accent'])preview.style.setProperty('--brand-'+k,form.elements.namedItem('presentation['+k+']').value);
  for(const input of form.querySelectorAll('[name="sections[]"]'))preview.append(preview.querySelector('[data-layout-section="'+input.value+'"]'));
 };
 form.addEventListener('input',dirty);
 form.addEventListener('submit',event=>{form.elements.namedItem('action').value=event.submitter?.dataset.layoutAction||'layout-save';});
 document.querySelectorAll('[data-device]').forEach(button=>button.addEventListener('click',()=>{preview.classList.remove('viewport-desktop','viewport-tablet','viewport-mobile');preview.classList.add('viewport-'+button.dataset.device);document.querySelectorAll('[data-device]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));}));
 form.addEventListener('click',event=>{
  const button=event.target.closest('[data-move]');if(!button)return;
  const row=button.closest('li'),list=row.parentNode;
  if(button.dataset.move==='up'&&row.previousElementSibling)list.insertBefore(row,row.previousElementSibling);
  if(button.dataset.move==='down'&&row.nextElementSibling)list.insertBefore(row.nextElementSibling,row);
  button.focus();dirty();
 });
 document.querySelectorAll('.preview-toolbar a').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();const size=new URL(link.href).searchParams.get('size');preview.classList.remove('viewport-desktop','viewport-tablet','viewport-mobile');preview.classList.add('viewport-'+size);document.querySelectorAll('.preview-toolbar a').forEach(a=>a.removeAttribute('aria-current'));link.setAttribute('aria-current','page');}));
});
