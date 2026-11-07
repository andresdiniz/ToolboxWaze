// ==UserScript==
// @name         ToolboxWaze — Central de solicitações WME
// @namespace    https://github.com/andresdiniz/ToolboxWaze
// @version      3.0.0
// @description  Modal independente, contexto SDK, formulários API, anexos e rascunhos.
// @match        https://www.waze.com/editor*
// @match        https://www.waze.com/*/editor*
// @match        https://beta.waze.com/editor*
// @match        https://beta.waze.com/*/editor*
// @exclude      https://www.waze.com/user/editor*
// @grant        unsafeWindow
// @grant        GM_getValue
// @grant        GM_setValue
// @grant        GM_deleteValue
// @grant        GM_xmlhttpRequest
// @connect      wazetoolbox.acheireviews.com.br
// @run-at       document-idle
// ==/UserScript==
/* global unsafeWindow, GM_getValue, GM_setValue, GM_deleteValue, GM_xmlhttpRequest */
(async function () {
  'use strict';
  if (window.top !== window.self) return;
  const VERSION='3.0.0', BASE='https://wazetoolbox.acheireviews.com.br';
  const API=BASE+'/api/wme', PREFIX='[ToolboxWaze]', PAGE=typeof unsafeWindow==='undefined'?window:unsafeWindow;
  const log=(...a)=>console.info(PREFIX,...a), warn=(...a)=>console.warn(PREFIX,...a);
  const store={get:async(k,d=null)=>await GM_getValue(k,d),set:async(k,v)=>await GM_setValue(k,v),del:async k=>await GM_deleteValue(k)};
  const uid=()=>crypto.randomUUID();
  let sdk=null, token=await store.get('toolbox_api_token',''), identity=null, forms=[], definition=null;
  let answers={}, files={}, context=null, dirty=false, busy=false, unresolved=null, previousFocus=null, loadSerial=0;
  let restoring=false, saveTimer=null, restoredPending=await store.get('tw3_pending',null);
  unresolved=restoredPending;
  const host=document.createElement('div'); host.id='toolbox-wme-v3';
  if(document.getElementById(host.id)) return;
  document.body.append(host); const root=host.attachShadow({mode:'open'});
  const el=(tag,text,parent,cls)=>{const n=document.createElement(tag);if(text!=null)n.textContent=text;if(cls)n.className=cls;if(parent)parent.append(n);return n;};
  const css=el('style',null,root);
  css.textContent=`
  :host{all:initial;font:14px/1.5 system-ui,sans-serif;color:#16342b;position:relative;z-index:2147483646}
  *{box-sizing:border-box} [hidden]{display:none!important} button,input,select,textarea{font:inherit}
  button,a{touch-action:manipulation}button{cursor:pointer;border:1px solid #cbded5;border-radius:9px;background:#fff;color:#163d2b;padding:9px 13px;font-weight:600}
  button:hover{background:#edf7f1}button:disabled{opacity:.5;cursor:not-allowed}a{color:#147348}
  button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,a:focus-visible{outline:3px solid #71bca0;outline-offset:2px}
  .fab{position:fixed;bottom:76px;right:22px;background:#176d4c;color:white;border:0;border-radius:28px;box-shadow:0 8px 28px #0004;padding:14px 20px}
  .overlay{position:fixed;inset:0;background:#0b241dcc;display:grid;place-items:center;padding:20px;backdrop-filter:blur(3px)}
  .dialog{width:min(1050px,97vw);height:min(850px,94vh);display:flex;flex-direction:column;background:#f4f8f5;border-radius:18px;box-shadow:0 24px 90px #0006;overflow:hidden}
  header,footer{background:#fff;padding:16px 22px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}header{border-bottom:1px solid #dce9e1}footer{border-top:1px solid #dce9e1}
  h2{font-size:19px;margin:0}h3{font-size:14px;margin:0 0 10px}p{margin:4px 0 12px}.muted,small{color:#5c7468;font-size:12px}.spacer{flex:1}
  .scroll{overflow:auto;flex:1;padding:20px}.layout{display:grid;grid-template-columns:270px 1fr;gap:18px;align-items:start}
  .card{padding:16px;border:1px solid #dce9e1;border-radius:12px;background:#fff;margin-bottom:14px}.green{background:#eef8f2}
  .types{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.types button{text-align:left;font-size:13px}.types button.active{background:#e4f5eb;border-color:#198754;box-shadow:0 0 0 2px #19875422}
  label{display:block;font-weight:600;margin-bottom:6px}input:not([type=checkbox]):not([type=radio]),select,textarea{width:100%;border:1px solid #cbded5;border-radius:8px;padding:10px;background:white;color:#183c2b}
  textarea{min-height:95px;resize:vertical}.field{margin-bottom:18px}.choices{display:flex;flex-wrap:wrap;gap:8px}.choice{display:flex;align-items:center;gap:7px;border:1px solid #dce9e1;border-radius:9px;padding:8px;font-size:13px;font-weight:400;margin:0}
  .choice:has(input:checked){border-color:#198754;background:#edf8f1}.primary{background:#198754;color:white;border-color:#198754}.primary:hover{background:#126a40}
  .status{white-space:pre-wrap;padding:10px 14px;border-radius:9px;margin-bottom:12px;background:#e8f2ed}.status.error{background:#fff0ef;color:#a32121}.status.success{background:#ddf4e7;color:#125d39}
  .error-text{color:#b3261e;font-size:12px;margin-top:5px}.bad{border-color:#b3261e!important}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.6 system-ui;margin:0;max-height:260px;overflow:auto}
  .row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.preview{display:flex;gap:8px;flex-wrap:wrap}.preview figure{margin:8px 0;border:1px solid #dce9e1;border-radius:8px;padding:6px;width:130px}.preview img{width:116px;height:80px;object-fit:cover}.preview small{display:block;overflow-wrap:anywhere}.preview button{padding:3px 7px;font-size:11px}
  @media(max-width:760px){.overlay{padding:5px}.dialog{height:98vh;width:99vw;border-radius:10px}.layout{grid-template-columns:1fr}.scroll{padding:12px}.types{grid-template-columns:1fr 1fr}header,footer{padding:12px}.side pre{max-height:110px}}
  `;
  const fab=el('button','Toolbox · Solicitações',root,'fab');fab.type='button';
  const overlay=el('div',null,root,'overlay');overlay.hidden=true;
  const dialog=el('section',null,overlay,'dialog');dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-labelledby','tw3-title');
  const header=el('header',null,dialog);const title=el('h2','Solicitações',header);title.id='tw3-title';
  el('span','ToolboxWaze · '+VERSION,header,'muted');el('span',null,header,'spacer');
  const settingsButton=el('button','Conexão',header),closeButton=el('button','Fechar',header);
  const scroll=el('div',null,dialog,'scroll');
  const settings=el('section',null,scroll,'card');settings.hidden=true;
  el('h3','Conectar ao ToolboxWaze',settings);el('p','Use um token pessoal. Nenhuma credencial ou cookie do Waze será enviado.',settings,'muted');
  const tokenLabel=el('label','Token da API',settings),tokenInput=el('input',null,settings);tokenInput.type='password';tokenInput.autocomplete='off';tokenInput.id='tw3-token';tokenLabel.htmlFor=tokenInput.id;
  const connectionRow=el('div',null,settings,'row');connectionRow.style.marginTop='10px';
  const connectButton=el('button','Validar e conectar',connectionRow,'primary'),forgetButton=el('button','Remover token',connectionRow);
  const tokenLink=el('a','Gerar token no site',connectionRow);tokenLink.href=BASE+'/profile/api-token';tokenLink.target='_blank';tokenLink.rel='noopener noreferrer';
  const status=el('div',null,scroll,'status');status.setAttribute('role','status');status.setAttribute('aria-live','polite');status.hidden=true;
  const pendingBox=el('div',null,scroll,'card');pendingBox.hidden=true;el('p','Há um envio sem resultado confirmado. Consulte antes de enviar novamente.',pendingBox);
  const checkButton=el('button','Consultar resultado do envio',pendingBox);
  const layout=el('div',null,scroll,'layout'),side=el('aside',null,layout,'side'),main=el('div',null,layout);
  const account=el('div',null,side,'card');el('h3','Sua conexão',account);const accountText=el('p','Desconectado',account,'muted');
  const contextBox=el('section',null,side,'card green');el('h3','Contexto do WME',contextBox);const summary=el('pre','Aguardando SDK…',contextBox);
  const contextRow=el('div',null,contextBox,'row');contextRow.style.marginTop='12px';const refresh=el('button','Atualizar',contextRow),copyButton=el('button','Copiar',contextRow);
  const contextNotice=el('p','O bloqueio do objeto não é o nível do editor.',contextBox,'muted');
  const typeCard=el('section',null,main,'card');el('h3','1. Escolha a solicitação',typeCard);const typeList=el('div',null,typeCard,'types');
  const formCard=el('section',null,main,'card');const formTitle=el('h3','2. Dados da solicitação',formCard),formDescription=el('p','Conecte para carregar os formulários disponíveis.',formCard,'muted');
  const fieldArea=el('div',null,formCard);
  const footer=el('footer',null,dialog),draftNote=el('small','Rascunho local por formulário',footer);el('span',null,footer,'spacer');
  const clearButton=el('button','Limpar rascunho',footer),sendButton=el('button','Enviar solicitação',footer,'primary');sendButton.disabled=true;
  const previews=new Set();
  function notice(text,type=''){status.textContent=text;status.className='status '+type;status.hidden=!text;}
  function updateBusy(){sendButton.disabled=busy||!identity||!definition||Boolean(unresolved);connectButton.disabled=busy;clearButton.disabled=busy;refresh.disabled=busy;pendingBox.hidden=!unresolved;}
  function api(method,path,body,credential=token){
    return new Promise((resolve,reject)=>{
      const start=performance.now();log('HTTP',method,path);
      const multipart=body instanceof FormData;
      GM_xmlhttpRequest({method,url:API+path,anonymous:true,timeout:25000,
        headers:{Accept:'application/json','X-API-TOKEN':credential,...(body&&!multipart?{'Content-Type':'application/json'}:{})},
        data:body?(multipart?body:JSON.stringify(body)):undefined,
        onload:r=>{log('HTTP',r.status,path,Math.round(performance.now()-start)+'ms');let data=null;try{data=JSON.parse(r.responseText);}catch{}
          if(r.status<200||r.status>=300){const e=new Error(data?.message||data?.error||'HTTP '+r.status);e.status=r.status;e.errors=data?.errors||{};reject(e);return;}
          if(!data||typeof data!=='object'){const e=new Error('A API não retornou JSON válido.');e.uncertain=true;reject(e);return;}resolve(data);},
        onerror:()=>reject(Object.assign(new Error('Falha de rede. O servidor pode ter recebido o envio.'),{uncertain:true})),
        ontimeout:()=>reject(Object.assign(new Error('Tempo esgotado. Consulte o resultado antes de reenviar.'),{uncertain:true}))
      });
    });
  }
  function selection(){try{const s=sdk?.Editing?.getSelection?.();if(s&&Array.isArray(s.ids))return s.ids.map(id=>({id:String(id),type:String(s.objectType).toLowerCase()}));}catch(e){warn('Seleção:',e.message);}return [];}
  function collect(){
    const selected=selection();const objects=selected.slice(0,50).map(item=>{const o={...item};try{
      const obj=item.type==='segment'?sdk.DataModel.Segments.getById({segmentId:Number(item.id)}):item.type==='venue'?sdk.DataModel.Venues.getById({venueId:item.id}):null;
      if(obj){o.name=obj.name||null;o.roadType=obj.roadType??null;o.lockLevel=Number.isFinite(obj.lockLevel)?obj.lockLevel:null;
        if(obj.primaryStreetId!=null)o.streetName=sdk.DataModel.Streets.getById({streetId:obj.primaryStreetId})?.name||null;}
    }catch(e){warn('Leitura parcial:',item.id,e.message);}return o;});
    let center=null,zoom=null;try{center=sdk.Map.getMapCenter();zoom=sdk.Map.getZoomLevel();}catch{}
    const u=new URL(location.href);u.hash='';['tab','s','marker','segments','venues'].forEach(k=>u.searchParams.delete(k));
    if(Number.isFinite(center?.lat)&&Number.isFinite(center?.lon)){u.searchParams.set('lat',center.lat);u.searchParams.set('lon',center.lon);}
    if(Number.isFinite(zoom)){u.searchParams.delete('zoom');u.searchParams.set('zoomLevel',zoom);}
    for(const [type,key]of [['segment','segments'],['venue','venues']]){const ids=objects.filter(o=>o.type===type).map(o=>o.id);if(ids.length)u.searchParams.set(key,ids.join(','));}
    return {capturadoEm:new Date().toISOString(),permalink:u.href,center,zoom,totalSelecionados:selected.length,objetos:objects};
  }
  function summarize(){if(!context)return 'SDK ainda indisponível. Você pode preencher os campos manualmente.';return ['Capturado: '+new Date(context.capturadoEm).toLocaleTimeString(),'Selecionados: '+context.totalSelecionados,'Zoom: '+(context.zoom??'indisponível'),...context.objetos.map(o=>[o.type+' #'+o.id,o.streetName||o.name,o.lockLevel==null?null:'Bloqueio '+o.lockLevel].filter(Boolean).join(' · ')),context.totalSelecionados>50?'Contexto limitado a 50 objetos.':null].filter(Boolean).join('\n');}
  function capture(){if(!sdk){notice('SDK ainda não disponível.','error');return;}context=collect();summary.textContent=summarize();contextNotice.textContent='Contexto atualizado. Bloqueio do objeto não é nível do editor.';if(definition){prefill();renderFields();scheduleSave();}}
  function source(path){
    if(path==='wme.permalink')return context?.permalink;
    if(path==='wme.segmentIds')return context?.objetos.filter(o=>o.type==='segment').map(o=>o.id).join(',');
    if(path==='wme.venueName')return context?.objetos.find(o=>o.type==='venue')?.name;
    if(path==='wme.objectLockLevel')return context?.objetos.length===1?context.objetos[0].lockLevel:null;
    if(path==='account.wazeNickname')return identity?.wazeNickname;
    return null;
  }
  function prefill(){for(const f of definition.campos){if(f.preenchimento&&(answers[f.chave]==null||answers[f.chave]==='')){const value=source(f.preenchimento.origem);if(value!=null)answers[f.chave]=value;}}}
  function visible(field,visited=new Set()){
    if(visited.has(field.chave))return false;visited.add(field.chave);
    return !field.mostrarSe||Object.entries(field.mostrarSe).every(([key,value])=>{const dep=definition.campos.find(f=>f.chave===key);return Boolean(dep)&&visible(dep,new Set(visited))&&(Array.isArray(value)?value.includes(answers[key]):answers[key]===value);});
  }
  function revokePreviews(){for(const url of previews)URL.revokeObjectURL(url);previews.clear();}
  function fieldError(key,text){const box=Array.from(fieldArea.children).find(n=>n.dataset.key===key);if(!box)return;const error=box.querySelector('.error-text');error.textContent=text;box.querySelectorAll('input,textarea,select').forEach(n=>{n.classList.add('bad');n.setAttribute('aria-invalid','true');});}
  function renderFields(){
    revokePreviews();fieldArea.replaceChildren();if(!definition)return;
    for(const f of definition.campos){if(!visible(f))continue;
      const box=el('div',null,fieldArea,'field');box.dataset.key=f.chave;const id='tw3-field-'+f.chave;
      const label=el('label',f.label+(f.obrigatorio?' *':''),box);label.htmlFor=id;
      const value=answers[f.chave];let input;
      const changed=v=>{answers[f.chave]=v;dirty=true;scheduleSave();if(definition.campos.some(x=>x.mostrarSe&&Object.hasOwn(x.mostrarSe,f.chave)))renderFields();};
      if(f.tipo==='radio'){
        const group=el('div',null,box,'choices');group.setAttribute('role','radiogroup');group.setAttribute('aria-label',f.label);
        for(const opt of f.opcoes||[]){const l=el('label',null,group,'choice');const r=el('input',null,l);r.type='radio';r.name=id;r.value=opt.value;r.checked=value===opt.value;el('span',opt.label,l);r.onchange=()=>changed(opt.value);}
      }else if(f.tipo==='checkbox'){
        input=el('input',null,box);input.type='checkbox';input.checked=value===true;input.onchange=()=>changed(input.checked);
      }else if(f.tipo==='select'){
        input=el('select',null,box);const empty=el('option','Escolher',input);empty.value='';for(const opt of f.opcoes||[]){const o=el('option',opt.label,input);o.value=opt.value;}input.value=value??'';input.onchange=()=>changed(input.value);
      }else if(f.tipo==='file'){
        input=el('input',null,box);input.type='file';input.multiple=(f.maxArquivos||1)>1;input.accept=(f.mimeTypes||['image/jpeg','image/png','image/webp']).join(',');
        input.onchange=()=>{files[f.chave]=Array.from(input.files||[]);dirty=true;renderFields();};
        const list=el('div',null,box,'preview');for(const [index,file]of (files[f.chave]||[]).entries()){
          const figure=el('figure',null,list);if(['image/jpeg','image/png','image/webp','image/gif'].includes(file.type)){const img=el('img',null,figure);const url=URL.createObjectURL(file);previews.add(url);img.src=url;img.alt=file.name;}
          el('small',file.name+' · '+Math.ceil(file.size/1024)+' KB',figure);const remove=el('button','Remover',figure);remove.onclick=()=>{files[f.chave].splice(index,1);renderFields();};
        }
      }else if(['text','textarea','number','email','url','date'].includes(f.tipo)){
        input=el(f.tipo==='textarea'?'textarea':'input',null,box);if(f.tipo!=='textarea')input.type=f.tipo;
        input.value=value??'';if(f.placeholder)input.placeholder=f.placeholder;
        if(f.maxLength)input.maxLength=f.maxLength;if(f.min!=null)input.min=f.min;if(f.max!=null)input.max=f.max;
        input.oninput=()=>{changed(input.value);if(counter)counter.textContent=input.value.length+(f.maxLength?' / '+f.maxLength:'')+' caracteres';};
        let counter=null;if(f.tipo==='textarea')counter=el('small',String(input.value.length)+' caracteres',box);
      }else{el('p','Tipo não suportado: '+f.tipo+'. Atualize o userscript.',box,'error-text');}
      if(input){input.id=id;input.setAttribute('aria-describedby',id+'-error');}
      if(f.ajuda)el('p',f.ajuda,box,'muted');
      if(f.preenchimento){const captureButton=el('button','Preencher do WME / conta',box);captureButton.type='button';captureButton.onclick=()=>{const v=source(f.preenchimento.origem);if(v!=null){changed(v);renderFields();}else notice('Dado indisponível para este campo. Preencha manualmente.','error');};}
      const error=el('div','',box,'error-text');error.id=id+'-error';error.setAttribute('role','alert');
    }
    updateBusy();
  }
  function validate(){
    const errors={};const allowed=['text','textarea','number','email','url','date','select','radio','checkbox','file'];
    for(const f of definition.campos){if(!visible(f))continue;const v=answers[f.chave],text=String(v??'').trim();let e='';
      if(!allowed.includes(f.tipo))e='Tipo de campo não suportado.';
      else if(f.tipo==='file'){const list=files[f.chave]||[];if(f.obrigatorio&&!list.length)e='Anexe um arquivo.';else if(list.length>(f.maxArquivos||1))e='Quantidade de arquivos excedida.';else if(list.some(x=>x.size>(f.maxBytes||1048576)))e='Arquivo maior que o permitido.';else if(list.some(x=>!(f.mimeTypes||['image/jpeg','image/png','image/webp']).includes(x.type)))e='Formato de arquivo não permitido.';}
      else if(f.obrigatorio&&(f.tipo==='checkbox'?v!==true:!text))e='Campo obrigatório.';
      else if(text&&f.minLength&&text.length<f.minLength)e='Mínimo de '+f.minLength+' caracteres.';
      else if(text&&f.maxLength&&text.length>f.maxLength)e='Máximo de '+f.maxLength+' caracteres.';
      else if(f.tipo==='number'&&text&&(!Number.isFinite(Number(v))||(f.inteiro&& !Number.isInteger(Number(v)))||(f.min!=null&&Number(v)<f.min)||(f.max!=null&&Number(v)>f.max)))e='Número fora dos limites permitidos.';
      else if(['select','radio'].includes(f.tipo)&&text&&!(f.opcoes||[]).some(o=>o.value===v))e='Opção inválida.';
      else if(f.valorObrigatorio!==undefined&&v!==f.valorObrigatorio)e='Confirmação necessária.';
      else if(f.tipo==='email'&&text&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text))e='E-mail inválido.';
      else if(f.tipo==='url'&&text){try{const u=new URL(text);if(!['http:','https:'].includes(u.protocol))throw Error();}catch{e='URL inválida.';}}
      if(!e&&text&&f.validacao==='permalink'){try{const u=new URL(text);const z=Number(u.searchParams.get('zoomLevel')??u.searchParams.get('zoom'));if(!['https:','http:'].includes(u.protocol)||!['www.waze.com','beta.waze.com','waze.com'].includes(u.hostname)||!/^\/(?:[a-zA-Z-]+\/)?editor\/?$/.test(u.pathname)||z<(f.zoomMinimo||15))e='Use um permalink WME com zoom mínimo '+(f.zoomMinimo||15)+'.';}catch{e='Permalink inválido.';}}
      if(!e&&text&&f.validacao==='wkt'&&!/^(POLYGON|LINESTRING)\s*\(/i.test(text))e='Use POLYGON(( ou LINESTRING(.';
      if(e)errors[f.chave]=e;
    }return errors;
  }
  async function draftKey(){return 'tw3_draft_'+String(identity.id)+'_'+definition.slug;}
  function scheduleSave(){if(restoring||!identity||!definition)return;clearTimeout(saveTimer);const key='tw3_draft_'+String(identity.id)+'_'+definition.slug;const snapshot={answers:structuredClone(answers),context,updatedAt:Date.now()};saveTimer=setTimeout(()=>store.set(key,snapshot).then(()=>{draftNote.textContent='Rascunho salvo · '+new Date().toLocaleTimeString();}).catch(()=>{draftNote.textContent='Falha ao salvar rascunho';}),350);}
  async function choose(slug){if(busy||unresolved)return;if(dirty)scheduleSave();const serial=++loadSerial;sendButton.disabled=true;notice('Carregando formulário…');try{
    const data=await api('GET','/formularios/'+encodeURIComponent(slug));if(serial!==loadSerial)return;
    if(data.slug!==slug||!Array.isArray(data.campos)||new Set(data.campos.map(f=>f.chave)).size!==data.campos.length||data.campos.some(f=>!/^[-a-zA-Z0-9_]+$/.test(f.chave)))throw new Error('Definição de formulário inválida.');
    definition=data;restoring=true;answers={};files={};const saved=await store.get(await draftKey());if(saved&&Date.now()-saved.updatedAt<7*86400000){answers=saved.answers||{};if(saved.context)context=saved.context;}
    prefill();formTitle.textContent=data.nome;formDescription.textContent=data.descricao||'';summary.textContent=summarize();renderFields();dirty=false;restoring=false;
    for(const b of typeList.children)b.classList.toggle('active',b.dataset.slug===slug);notice('');updateBusy();
  }catch(e){restoring=false;definition=null;notice(e.message,'error');updateBusy();}}
  async function connect(candidate=token){
    if(!candidate){settings.hidden=false;notice('Informe seu token pessoal.','error');return;}
    busy=true;updateBusy();try{const data=await api('GET','/bootstrap',null,candidate);if(!data.usuario?.id||!Array.isArray(data.formularios))throw new Error('Resposta de bootstrap inválida.');
      clearTimeout(saveTimer);definition=null;answers={};files={};fieldArea.replaceChildren();token=candidate;await store.set('toolbox_api_token',token);identity=data.usuario;forms=data.formularios;tokenInput.value='';settings.hidden=true;
      accountText.textContent=(identity.nome||identity.wazeNickname||'Usuário')+' · '+(identity.wazeNickname||'');typeList.replaceChildren();
      for(const f of forms){const b=el('button',f.nome,typeList);b.dataset.slug=f.slug;b.onclick=()=>choose(f.slug);}
      notice(forms.length?'Conectado. Escolha um formulário.':'Nenhum formulário disponível para sua conta.','success');
    }catch(e){identity=null;definition=null;forms=[];typeList.replaceChildren();accountText.textContent='Não conectado';notice(e.message,'error');settings.hidden=false;}finally{busy=false;updateBusy();}
  }
  async function result(data){if(!data.solicitacao?.id)throw Object.assign(new Error('Resposta sem identificador de solicitação.'),{uncertain:true});
    unresolved=null;await store.del('tw3_pending');notice('Solicitação #'+data.solicitacao.id+' criada com sucesso.','success');
    if(data.solicitacao.url){try{const u=new URL(data.solicitacao.url,BASE);if(u.origin===BASE){const a=el('a',' Abrir no ToolboxWaze',status);a.href=u.href;a.target='_blank';a.rel='noopener noreferrer';}}catch{}}
    if(definition)await store.del(await draftKey());clearTimeout(saveTimer);answers={};files={};dirty=false;renderFields();draftNote.textContent='Enviado. Anexos e rascunho removidos.';
  }
  async function send(){if(busy||!identity||!definition||unresolved)return;
    renderFields();const errors=validate();if(Object.keys(errors).length){for(const [k,v]of Object.entries(errors))fieldError(k,v);notice('Revise os campos destacados.','error');fieldArea.querySelector('.bad')?.focus();return;}
    const key=uid();const payload={formulario:definition.slug,versaoFormulario:definition.versao,respostas:{},contexto:context,origem:'wme-userscript',scriptVersion:VERSION,clientRequestId:key};
    for(const f of definition.campos){if(visible(f)&&f.tipo!=='file'&&answers[f.chave]!==undefined)payload.respostas[f.chave]=f.tipo==='number'&&answers[f.chave]!==''?Number(answers[f.chave]):answers[f.chave];}
    const body=new FormData();body.append('payload',JSON.stringify(payload));for(const f of definition.campos){if(visible(f)&&f.tipo==='file')for(const file of files[f.chave]||[])body.append('anexos['+f.chave+'][]',file,file.name);}
    busy=true;unresolved={key,accountId:identity.id,createdAt:Date.now(),formulario:definition.slug};
    try{await store.set('tw3_pending',unresolved);updateBusy();notice('Enviando… Não feche o navegador.');const data=await api('POST','/solicitacoes',body);await result(data);}
    catch(e){if(e.status&&e.status>=400&&e.status<500&&e.status!==409){unresolved=null;await store.del('tw3_pending');}notice(e.message+(unresolved?'\nNão repita o envio. Consulte o resultado.':''),'error');for(const [k,v]of Object.entries(e.errors||{}))fieldError(k,Array.isArray(v)?v.join(' '):String(v));}
    finally{busy=false;updateBusy();}
  }
  async function checkPending(){if(busy||!unresolved)return;if(!identity||String(identity.id)!==String(unresolved.accountId)){notice('Conecte a conta usada no envio pendente.','error');return;}busy=true;updateBusy();try{
    const data=await api('GET','/envios/'+encodeURIComponent(unresolved.key));if(data.estado==='concluido')await result(data);
    else if(data.estado==='nao_criado'){unresolved=null;await store.del('tw3_pending');notice('O servidor confirmou que a solicitação não foi criada. Você pode enviar novamente.');}
    else notice('Envio ainda em processamento. Consulte novamente em instantes.');
  }catch(e){notice('Não foi possível confirmar: '+e.message,'error');}finally{busy=false;updateBusy();}}
  function open(){previousFocus=document.activeElement;overlay.hidden=false;if(!context&&sdk){context=collect();summary.textContent=summarize();}if(!identity){settings.hidden=false;tokenInput.focus();if(token)connect();}else closeButton.focus();updateBusy();}
  function close(){if(busy){notice('Aguarde a operação terminar.');return;}if(dirty)scheduleSave();overlay.hidden=true;previousFocus?.focus();}
  fab.onclick=open;closeButton.onclick=close;settingsButton.onclick=()=>{settings.hidden=!settings.hidden;if(!settings.hidden)tokenInput.focus();};
  connectButton.onclick=()=>connect(tokenInput.value.trim()||token);
  forgetButton.onclick=async()=>{if(busy)return;clearTimeout(saveTimer);await store.del('toolbox_api_token');token='';identity=null;definition=null;answers={};files={};revokePreviews();fieldArea.replaceChildren();typeList.replaceChildren();accountText.textContent='Desconectado';tokenInput.value='';updateBusy();notice('Token removido deste userscript.');};
  refresh.onclick=()=>{if(dirty&&!window.confirm('Atualizar o contexto do WME? Campos já preenchidos serão preservados.'))return;capture();};
  copyButton.onclick=async()=>{try{await navigator.clipboard.writeText(JSON.stringify(context,null,2));notice('Contexto copiado.');}catch{notice('O navegador bloqueou a cópia.','error');}};
  sendButton.onclick=send;checkButton.onclick=checkPending;
  clearButton.onclick=async()=>{if(!definition||!window.confirm('Apagar o rascunho deste formulário?'))return;clearTimeout(saveTimer);await store.del(await draftKey());answers={};files={};dirty=false;prefill();renderFields();draftNote.textContent='Rascunho limpo.';};
  overlay.addEventListener('click',e=>{if(e.target===overlay)close();});
  root.addEventListener('keydown',e=>{if(overlay.hidden)return;if(e.key==='Escape'){e.preventDefault();e.stopPropagation();close();}if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){e.preventDefault();if(e.composedPath().includes(settings))return;send();}
    if(e.key==='Tab'){const nodes=Array.from(dialog.querySelectorAll('button,a,input,select,textarea')).filter(n=>!n.disabled&&n.getClientRects().length);const first=nodes[0],last=nodes.at(-1),active=root.activeElement;if(e.shiftKey&&active===first){e.preventDefault();last?.focus();}else if(!e.shiftKey&&active===last){e.preventDefault();first?.focus();}}
  });
  PAGE.twDiagnostico=()=>{const d={version:VERSION,sdk:!!sdk,connected:!!identity,form:definition?.slug,selected:selection().length,pending:!!unresolved,modalOpen:!overlay.hidden};log(d);return d;};
  log('Interface carregada',VERSION);updateBusy();
  try{for(let i=0;i<200;i++){if(PAGE.SDK_INITIALIZED&&typeof PAGE.getWmeSdk==='function')break;if(i===199)throw new Error('SDK indisponível após 60 segundos.');await new Promise(r=>setTimeout(r,300));}
    await PAGE.SDK_INITIALIZED;sdk=PAGE.getWmeSdk({scriptId:'toolboxwaze-solicitacoes',scriptName:'ToolboxWaze — Solicitações'});
    if(!sdk.State.isReady())await sdk.Events.once({eventName:'wme-ready'});context=collect();summary.textContent=summarize();
    sdk.Events.on({eventName:'wme-selection-changed',eventHandler:()=>{const n=selection().length;fab.textContent='Toolbox · Solicitações'+(n?' ('+n+')':'');if(!overlay.hidden)contextNotice.textContent='A seleção pode ter mudado. Use Atualizar para recapturar.';}});log('SDK pronto');
  }catch(e){summary.textContent='SDK indisponível. Preenchimento manual disponível.';warn(e.message);}
})();
