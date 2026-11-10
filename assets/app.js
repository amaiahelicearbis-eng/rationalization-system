(() => {
  const modal = document.getElementById('recordModal');
  const form = document.getElementById('recordForm');
  const title = document.getElementById('modalTitle');
  const mode = document.getElementById('recordMode');
  const modelCode = document.getElementById('modelCode');
  const modelName = document.getElementById('modelName');
  const qtyInputs = [...document.querySelectorAll('#slotGrid input')];
  const total = document.getElementById('qtyTotal');
  const open = () => { modal.classList.add('open'); modal.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; setTimeout(()=>modelCode.focus(),50); };
  const close = () => { modal.classList.remove('open'); modal.setAttribute('aria-hidden','true'); document.body.style.overflow=''; };
  const updateTotal = () => { total.textContent = qtyInputs.reduce((s,i)=>s+(Number.parseInt(i.value,10)||0),0).toLocaleString(); };
  const resetForm = () => { form.reset(); mode.value='create'; modelCode.readOnly=false; modelCode.classList.remove('readonly'); title.textContent='New production record'; qtyInputs.forEach(i=>i.value='0'); updateTotal(); };
  document.getElementById('newRecord')?.addEventListener('click',()=>{resetForm();open();});
  document.getElementById('closeModal')?.addEventListener('click',close);
  document.getElementById('cancelModal')?.addEventListener('click',close);
  modal?.addEventListener('click',e=>{if(e.target===modal)close();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('open'))close();});
  document.querySelectorAll('.edit-btn').forEach(btn=>btn.addEventListener('click',()=>{
    resetForm(); const r=JSON.parse(btn.dataset.record); mode.value='edit'; title.textContent='Edit production record'; modelCode.value=r.model; modelCode.readOnly=true; modelCode.classList.add('readonly'); modelName.value=r.name;
    qtyInputs.forEach((input,i)=>input.value=String(r.qty[i]??0)); updateTotal(); open();
  }));
  qtyInputs.forEach(i=>i.addEventListener('input',updateTotal));
  document.getElementById('menuToggle')?.addEventListener('click',()=>document.getElementById('sidebar')?.classList.toggle('show'));
  form?.addEventListener('submit',e=>{for(const i of qtyInputs){if(i.value===''||Number(i.value)<0||!Number.isInteger(Number(i.value))){e.preventDefault();i.focus();alert('Enter a non-negative whole number for each time slot.');return;}}});
  updateTotal();
})();
