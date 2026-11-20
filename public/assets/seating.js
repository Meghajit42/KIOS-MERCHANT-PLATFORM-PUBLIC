'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const root=document.querySelector('[data-seating]');if(!root)return;
 const tiles=[...root.querySelectorAll('[data-table-select]')],panels=[...root.querySelectorAll('[data-table-details]')],filter=root.querySelector('[data-floor-filter]');
 function select(id){for(const tile of tiles)tile.setAttribute('aria-pressed',String(tile.dataset.tableSelect===id));for(const panel of panels)panel.hidden=panel.dataset.tableDetails!==id;root.querySelector('[data-table-prompt]').hidden=!!id;}
 tiles.forEach(t=>t.addEventListener('click',()=>select(t.dataset.tableSelect)));
 function floor(){let n=0,capacity=0;for(const t of tiles){t.hidden=!!filter.value&&t.dataset.floor!==filter.value;if(!t.hidden&&t.dataset.active==='1'){n++;capacity+=Number(t.dataset.capacity);}}select('');root.querySelector('[data-floor-summary]').textContent=n+' active tables · '+capacity+' configured seats in this view';}
 filter.addEventListener('change',floor);floor();
 root.querySelectorAll('[data-reserve-table]').forEach(b=>b.addEventListener('click',()=>{const form=root.querySelector('form:has([value="seating-reserve"])');form.elements.table.value=b.dataset.reserveTable;root.querySelector('#new-reservation').scrollIntoView({block:'start'});form.elements.guest.focus();}));
 const search=root.querySelector('[data-reservation-search]'),status=root.querySelector('[data-reservation-status]'),period=root.querySelector('[data-reservation-period]'),cards=[...root.querySelectorAll('[data-reservation]')];
 function bookings(){let n=0;for(const c of cards){c.hidden=!c.textContent.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase())||(!!status.value&&c.dataset.status!==status.value)||(!!period.value&&c.dataset[period.value]!=='1');if(!c.hidden)n++;}root.querySelector('[data-reservation-count]').textContent=n+' of '+cards.length+' loaded reservations shown';}
 for(const input of [search,status,period])input.addEventListener('input',bookings);bookings();
 root.querySelectorAll('[data-seating-form]').forEach(f=>f.addEventListener('submit',e=>{if(f.dataset.submitting==='1'){e.preventDefault();return;}if(e.defaultPrevented)return;f.dataset.submitting='1';for(const b of f.querySelectorAll('button')){b.disabled=true;b.setAttribute('aria-disabled','true');}f.setAttribute('aria-busy','true');}));
});
