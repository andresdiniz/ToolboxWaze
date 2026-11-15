(() => {
  'use strict';
  function init() {
    const host = document.querySelector('[data-form-studio]');
    if (!host || host.dataset.ready) return;
    host.dataset.ready = '1';
    const $ = id => host.querySelector('#' + id);
    const state = JSON.parse($('fbs-initial').textContent);
    let selected = -1, dirty = false;
    const types = {text:'Texto curto',textarea:'Texto longo',number:'Número',email:'E-mail',url:'URL',date:'Data',select:'Lista',radio:'Escolha única',checkbox:'Confirmação',file:'Anexo'};
    const el = (tag, text, parent, cls) => { const n=document.createElement(tag);if(text!==null)n.textContent=text;if(cls)n.className=cls;if(parent)parent.append(n);return n; };
    const message = text => { $('fbs-message').textContent=text;$('fbs-message').hidden=!text; };
    const unique = base => {let key=base,i=2;while(state.campos.some(c=>c.chave===key))key=base+'_'+i++;return key;};
    const collect = () => ({...state,nome:$('fbs-name').value.trim(),slug:$('fbs-slug').value.trim(),descricao:$('fbs-desc').value.trim(),ativo:$('fbs-active').checked});
    host.addEventListener('input',()=>{dirty=true;});
    for(const [type,label] of Object.entries(types)){const b=el('button',label,$('fbs-types'),'fbs-tool');b.type='button';b.onclick=()=>{state.campos.push({chave:unique(type+'_campo'),label,tipo:type,obrigatorio:false,placeholder:null,ajuda:null,valor_padrao:null,opcoes:['select','radio'].includes(type)?{opcoes:[{value:'opcao_1',label:'Opção 1'}]}:{}});selected=state.campos.length-1;dirty=true;render();};}
    function render(){
      $('fbs-fields').replaceChildren();$('fbs-empty').hidden=state.campos.length>0;$('fbs-count').textContent=state.campos.length+' campos'+(dirty?' · alterações locais':'');
      state.campos.forEach((f,i)=>{const card=el('article',null,$('fbs-fields'),'card fbs-field'+(selected===i?' selected':''));const body=el('div',null,card,'card-body');el('strong',f.label+(f.obrigatorio?' *':''),body);el('div',null,body);el('code',f.chave+' · '+f.tipo,body);const actions=el('div',null,body,'fbs-field-actions');for(const [label,fn] of [['↑',()=>move(i,-1)],['↓',()=>move(i,1)],['Duplicar',()=>{const copy=structuredClone(f);delete copy.id;copy.chave=unique(f.chave+'_copia');state.campos.splice(i+1,0,copy);selected=i+1;dirty=true;render();}],['Remover',()=>{if(!window.confirm('Remover campo local? Campos existentes são protegidos pelo servidor.'))return;state.campos.splice(i,1);selected=-1;dirty=true;render();}]]){const b=el('button',label,actions,'btn btn-sm btn-outline-secondary');b.type='button';b.setAttribute('aria-label',label==='↑'?'Mover para cima':label==='↓'?'Mover para baixo':label);b.onclick=e=>{e.stopPropagation();fn();};}card.onclick=()=>{selected=i;render();};});props();
    }
    function move(i,d){const j=i+d;if(j<0||j>=state.campos.length)return;[state.campos[i],state.campos[j]]=[state.campos[j],state.campos[i]];selected=j;dirty=true;render();}
    function props(){const area=$('fbs-properties');area.replaceChildren();const f=state.campos[selected];if(!f){el('p','Selecione um campo.',area,'small text-muted');return;}
      for(const [key,label,max] of [['label','Rótulo',120],['chave','Chave interna',80],['placeholder','Placeholder',255],['ajuda','Ajuda',500],['valor_padrao','Valor padrão',500]]){const id='fbs-prop-'+key;const l=el('label',label,area,'form-label');l.htmlFor=id;const input=el('input',null,area,'form-control form-control-sm');input.id=id;input.maxLength=max;input.value=f[key]??'';input.onchange=()=>{f[key]=input.value||null;dirty=true;render();};}
      const label=el('label',null,area,'form-check mt-3');const cb=el('input',null,label,'form-check-input');cb.type='checkbox';cb.checked=f.obrigatorio;el('span','Obrigatório',label);cb.onchange=()=>{f.obrigatorio=cb.checked;dirty=true;render();};
      const l=el('label','Opções e regras (JSON)',area,'form-label');l.htmlFor='fbs-prop-json';const input=el('textarea',null,area,'form-control font-monospace');input.id='fbs-prop-json';input.rows=13;input.value=JSON.stringify(f.opcoes||{},null,2);input.oninput=()=>{input.setCustomValidity('');try{const v=JSON.parse(input.value);if(!v||Array.isArray(v)||typeof v!=='object')throw Error('Use objeto JSON.');f.opcoes=v;}catch(e){input.setCustomValidity('JSON inválido: '+e.message);}};el('small','Exemplo: {"mostrarSe":{"tipoNivel":"upgrade"}}. As opções existentes são preservadas.',area,'text-muted');
    }
    function payload(){const data=collect();if(!data.nome||!data.slug)throw Error('Preencha nome e slug.');const keys=new Set();for(const f of data.campos){if(!f.chave||!f.label||keys.has(f.chave))throw Error('Revise rótulos e chaves duplicadas.');keys.add(f.chave);}if(!$('fbs-form').reportValidity())throw Error('Revise os campos destacados.');return data;}
    $('fbs-form').onsubmit=e=>{try{$('fbs-definition').value=JSON.stringify(payload());dirty=false;}catch(error){e.preventDefault();message(error.message);}};
    $('fbs-export').onclick=()=>{try{const data=payload();const url=URL.createObjectURL(new Blob([JSON.stringify(data,null,2)],{type:'application/json'}));const a=el('a',null,host);a.href=url;a.download=data.slug+'.json';a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}catch(e){message(e.message);}};
    $('fbs-preview').onclick=()=>{try{const data=payload(),area=$('fbs-preview-content');area.replaceChildren();el('h3',data.nome,area);el('p',data.descricao,area);for(const f of data.campos){const wrap=el('div',null,area,'mb-3');const id='fbs-preview-'+f.chave;const label=el('label',f.label+(f.obrigatorio?' *':''),wrap,'form-label');label.htmlFor=id;if(['select','radio'].includes(f.tipo)){const s=el('select',null,wrap,'form-select');s.id=id;const raw=f.opcoes?.opcoes||[];const opts=Array.isArray(raw)?raw:Object.entries(raw).map(([value,label])=>({value,label}));for(const o of opts){const n=el('option',typeof o==='object'?o.label:String(o),s);n.value=typeof o==='object'?o.value:String(o);}}else if(['html','divider','heading'].includes(f.tipo)){el('p','Bloco de layout: '+f.tipo,wrap,'text-muted');}else{const n=el(f.tipo==='textarea'?'textarea':'input',null,wrap,'form-control');n.id=id;if(f.tipo!=='textarea')n.type=['number','email','url','date','time','checkbox','file'].includes(f.tipo)?f.tipo:'text';n.placeholder=f.placeholder||'';}if(f.ajuda)el('small',f.ajuda,wrap,'text-muted');}$('fbs-dialog').showModal();}catch(e){message(e.message);}};
    $('fbs-close').onclick=()=>$('fbs-dialog').close();window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});render();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
  document.addEventListener('turbo:load',init);
})();
