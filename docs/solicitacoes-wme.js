// ==UserScript==
// @name         ToolboxWaze — Central de solicitações WME
// @namespace    https://github.com/andresdiniz/ToolboxWaze
// @version      4.0.4
// @description  Solicitações ao ToolboxWaze direto do WME: conexão por e-mail, contexto SDK, formulários da API, anexos, rascunhos e histórico com links.
// @author       Andres Diniz
// @homepageURL  https://github.com/andresdiniz/ToolboxWaze
// @supportURL   https://github.com/andresdiniz/ToolboxWaze/issues
// @updateURL    https://raw.githubusercontent.com/andresdiniz/ToolboxWaze/main/ToolboxWaze.user.js
// @downloadURL  https://raw.githubusercontent.com/andresdiniz/ToolboxWaze/main/ToolboxWaze.user.js
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

(async function () {
  'use strict';
  if (window.top !== window.self) return;
  const VERSION='4.0.4', BASE='https://wazetoolbox.acheireviews.com.br';
  const API=BASE+'/api/wme', PREFIX='[ToolboxWaze]', PAGE=typeof unsafeWindow==='undefined'?window:unsafeWindow;
  const log=(...a)=>console.info(PREFIX,...a), warn=(...a)=>console.warn(PREFIX,...a);
  const store={get:async(k,d=null)=>await GM_getValue(k,d),set:async(k,v)=>await GM_setValue(k,v),del:async k=>await GM_deleteValue(k)};
  const uid=()=>crypto.randomUUID();
  const TOKEN_KEY='tw_device_token', LEGACY_KEY='toolbox_api_token';
  let sdk=null, token=(await store.get(TOKEN_KEY,''))||(await store.get(LEGACY_KEY,'')), identity=null, forms=[], definition=null;
  let conn='carregando', pendingInfo=null, pollTimer=null;
  const randomToken=()=>{const b=new Uint8Array(32);crypto.getRandomValues(b);return Array.from(b,x=>x.toString(16).padStart(2,'0')).join('');};
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
  .fab{position:fixed;bottom:76px;right:96px;background:#176d4c;color:white;border:0;border-radius:28px;box-shadow:0 8px 28px #0004;padding:14px 20px}
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
  @media(max-width:760px){.overlay{padding:5px}.dialog{height:98vh;width:99vw;border-radius:10px}.layout{grid-template-columns:1fr}.scroll{padding:12px}.types{grid-template-columns:1fr 1fr}header,footer{padding:12px}.side pre{max-height:110px}.fab{right:80px}}
  `;
  css.textContent += `.mine-request{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px;margin-bottom:8px;border:1px solid #dce9e1;border-radius:10px;text-decoration:none;color:#163d2b;background:#fff}.mine-request:hover{background:#eef8f2;border-color:#198754}.mine-request:focus-visible{outline:3px solid #71bca0;outline-offset:2px}.mine-open{margin-left:auto;font-size:12px;font-weight:600}`;
  css.textContent += `
  .steps ol{list-style:none;margin:0 0 12px;padding:0;display:grid;gap:8px}
  .steps li{display:grid;grid-template-columns:30px 1fr;gap:10px;align-items:start;padding:10px;border:1px solid #dce9e1;border-radius:10px;background:#f8fbf9}
  .steps .num{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:#dce9e1;color:#40594d;font-weight:700;font-size:13px}
  .steps li.current{border-color:#198754;background:#eef8f2;box-shadow:0 0 0 2px #19875422}.steps li.current .num,.steps li.done .num{background:#198754;color:#fff}.steps li.done{opacity:.75}
  .steps strong{display:block}.steps li span.muted{display:block}
  .why{background:#fff8e6;border:1px solid #f0dca0;border-radius:10px;padding:10px 12px;font-size:13px;color:#5b4a14}.why strong{color:#4a3b0d}.why p{margin:4px 0 0}
  .hint{font-size:12px;margin-top:5px;color:#5c7468}.hint.ok{color:#125d39}.hint.bad{color:#b3261e}`;
  const fab=el('button','Toolbox · Solicitações',root,'fab');fab.type='button';
  const overlay=el('div',null,root,'overlay');overlay.hidden=true;
  const dialog=el('section',null,overlay,'dialog');dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-labelledby','tw3-title');
  const header=el('header',null,dialog);const title=el('h2','Solicitações',header);title.id='tw3-title';
  el('span','ToolboxWaze · '+VERSION,header,'muted');el('span',null,header,'spacer');
  const mineButton=el('button','Minhas',header),settingsButton=el('button','Conexão',header),closeButton=el('button','Fechar',header);mineButton.hidden=true;
  const scroll=el('div',null,dialog,'scroll');
  const settings=el('section',null,scroll,'card');settings.hidden=true;
  el('h3','Conexão com o ToolboxWaze',settings);
  const connText=el('p','Não conectado.',settings,'muted');
  el('p','Para enviar solicitações é preciso estar conectado: você confirma o seu e-mail uma única vez e este navegador passa a usar um token aleatório gerado pelo script. Nenhuma senha, credencial ou cookie do Waze é enviado.',settings,'muted');
  const settingsRow=el('div',null,settings,'row');
  const retryButton=el('button','Tentar novamente',settingsRow,'primary'),disconnectButton=el('button','Desconectar este navegador',settingsRow);retryButton.hidden=true;
  const status=el('div',null,scroll,'status');status.setAttribute('role','status');status.setAttribute('aria-live','polite');status.hidden=true;
  const stepsCard=el('section',null,scroll,'card steps');stepsCard.hidden=true;
  el('h3','Como conectar (leva cerca de 1 minuto)',stepsCard);
  const stepsList=el('ol',null,stepsCard);
  const STEPS=[
    ['Informe seu e-mail','Usamos o apelido detectado no WME e o seu e-mail para criar (ou localizar) sua conta no ToolboxWaze.'],
    ['Confirme no e-mail','Abra a mensagem do ToolboxWaze e clique em “Confirmar e-mail”. O link vale 60 minutos e só funciona uma vez.'],
    ['Volte ao WME','O script detecta a confirmação sozinho (ou use “Já confirmei”). Este navegador fica conectado.'],
    ['Escolha o formulário e envie','Depois disso você não precisa repetir o processo neste navegador.']
  ];
  const stepItems=STEPS.map(([t,d],i)=>{const li=el('li',null,stepsList);el('span',String(i+1),li,'num');const box=el('div',null,li);el('strong',t,box);el('span',d,box,'muted');return li;});
  const why=el('div',null,stepsCard,'why');el('strong','Por que isso é necessário?',why);
  el('p','O ToolboxWaze precisa saber quem enviou cada solicitação para que os responsáveis possam responder e para evitar spam e abuso. O script não consegue provar quem você é no Waze, por isso confirmamos o seu e-mail uma única vez. Nenhuma senha nem cookie do Waze é enviado; o token fica guardado só neste navegador.',why);
  const registerCard=el('section',null,scroll,'card');registerCard.hidden=true;
  el('h3','Passo 1 de 4 · Informe seu e-mail',registerCard);
  el('p','Se você ainda não tem conta no ToolboxWaze, ela é criada automaticamente. Em seguida enviaremos um link para confirmar que o e-mail é seu.',registerCard,'muted');
  const nickField=el('div',null,registerCard,'field'),nickLabel=el('label','Apelido no WME',nickField),nickInput=el('input',null,nickField);nickInput.id='tw4-nick';nickLabel.htmlFor='tw4-nick';nickInput.maxLength=60;nickInput.autocomplete='off';
  const nickHint=el('small','',nickField),nickError=el('div','',nickField,'error-text');nickError.setAttribute('role','alert');
  const mailField=el('div',null,registerCard,'field'),mailLabel=el('label','E-mail',mailField),mailInput=el('input',null,mailField);mailInput.id='tw4-email';mailLabel.htmlFor='tw4-email';mailInput.type='email';mailInput.autocomplete='email';mailInput.maxLength=180;
  const mailError=el('div','',mailField,'error-text');mailError.setAttribute('role','alert');
  const nameField=el('div',null,registerCard,'field'),nameLabel=el('label','Nome (opcional)',nameField),nameInput=el('input',null,nameField);nameInput.id='tw4-name';nameLabel.htmlFor='tw4-name';nameInput.maxLength=120;nameInput.autocomplete='name';
  const consent=el('label',null,registerCard,'choice'),consentBox=el('input',null,consent);consentBox.type='checkbox';el('span','Concordo em enviar meu e-mail, meu apelido do WME e os dados das solicitações ao ToolboxWaze.',consent);
  const registerRow=el('div',null,registerCard,'row');registerRow.style.marginTop='12px';const registerButton=el('button','Conectar',registerRow,'primary');
  const waitCard=el('section',null,scroll,'card');waitCard.hidden=true;
  el('h3','Passo 2 de 4 · Confirme seu e-mail',waitCard);const waitText=el('p','',waitCard);
  el('p','O link vale por 60 minutos. Depois de confirmar, o script detecta sozinho (ou use “Já confirmei”). Não chegou? Olhe a caixa de spam ou use “Reenviar e-mail” (1 vez a cada 5 minutos).',waitCard,'muted');
  const waitRow=el('div',null,waitCard,'row');const verifyButton=el('button','Já confirmei',waitRow,'primary'),resendButton=el('button','Reenviar e-mail',waitRow),changeButton=el('button','Usar outro e-mail',waitRow);
  const mineCard=el('section',null,scroll,'card');mineCard.hidden=true;el('h3','Minhas solicitações',mineCard);const mineList=el('div',null,mineCard);
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
  function updateBusy(){sendButton.disabled=busy||conn!=='ativo'||!identity||!definition||!definition.campos?.length||Boolean(unresolved);registerButton.disabled=busy;verifyButton.disabled=busy;resendButton.disabled=busy;changeButton.disabled=busy;disconnectButton.disabled=busy;retryButton.disabled=busy;clearButton.disabled=busy;refresh.disabled=busy;pendingBox.hidden=!unresolved||conn!=='ativo';}
  function nonJsonMessage(r,type,path){
    const final=String(r.finalUrl||''),body=String(r.responseText||'');
    const snippet=body.replace(/<script[\s\S]*?<\/script>|<style[\s\S]*?<\/style>/gi,' ').replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,160);
    warn('Resposta não-JSON',{path,status:r.status,finalUrl:final,contentType:type,trecho:snippet});
    if(/\/login(?:[/?#]|$)/.test(final))return 'O servidor redirecionou '+path+' para a tela de login. A rota /api/wme não está liberada no servidor: confira config/packages/security.yaml, o WmeDeviceAuthenticator em services.yaml e rode cache:clear.';
    if(/html/i.test(type)||/^\s*</.test(body))return 'O servidor devolveu uma página HTML (HTTP '+r.status+') em vez de JSON em '+path+(snippet?'. Trecho: “'+snippet+'”':'')+'. Detalhes no console (F12).';
    return 'Resposta inesperada (HTTP '+r.status+') em '+path+'. Detalhes no console (F12).';
  }
  function api(method,path,body){
    const credential=token;
    return new Promise((resolve,reject)=>{
      const start=performance.now();log('HTTP',method,path);
      const multipart=body instanceof FormData;
      GM_xmlhttpRequest({method,url:API+path,anonymous:true,timeout:25000,
        headers:{Accept:'application/json','X-API-TOKEN':credential,...(body&&!multipart?{'Content-Type':'application/json'}:{})},
        data:body?(multipart?body:JSON.stringify(body)):undefined,
        onload:r=>{log('HTTP',r.status,path,Math.round(performance.now()-start)+'ms');let data=null;try{data=JSON.parse(r.responseText);}catch{}
          const type=(/content-type:\s*([^\r\n;]+)/i.exec(r.responseHeaders||'')||[])[1]?.trim()||'';
          if(r.status<200||r.status>=300){const e=new Error(data?.message||data?.error||(data&&typeof data==='object'?'HTTP '+r.status:nonJsonMessage(r,type,path)));e.status=r.status;e.codigo=data?.codigo||'';e.errors=data?.errors||{};e.data=data||{};reject(e);return;}
          if(!data||typeof data!=='object'){const e=new Error(nonJsonMessage(r,type,path));e.uncertain=true;e.status=r.status;reject(e);return;}resolve(data);},
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
  // Validação do permalink feita pelo script (antes de enviar ao servidor).
  function permalinkCheck(text,field){
    const min=field.zoomMinimo||15;let u;
    try{u=new URL(text);}catch{return {ok:false,msg:'Cole um link completo do WME (começa com https://www.waze.com/…/editor).'};}
    if(!['https:','http:'].includes(u.protocol)||!['www.waze.com','beta.waze.com','waze.com'].includes(u.hostname)||!/^\/(?:[a-zA-Z-]+\/)?editor\/?$/.test(u.pathname))return {ok:false,msg:'O link precisa ser um permalink do Waze Map Editor.'};
    const lat=Number(u.searchParams.get('lat')),lon=Number(u.searchParams.get('lon'));
    if(!u.searchParams.has('lat')||!u.searchParams.has('lon')||!Number.isFinite(lat)||!Number.isFinite(lon)||Math.abs(lat)>90||Math.abs(lon)>180)return {ok:false,msg:'O link não tem coordenadas (lat/lon). Use “Usar posição atual do mapa”.'};
    const raw=u.searchParams.get('zoomLevel')??u.searchParams.get('zoom'),z=Number(raw);
    if(raw==null||raw===''||!Number.isFinite(z))return {ok:false,msg:'O link não informa o zoom. Use “Usar posição atual do mapa”.'};
    if(z<min)return {ok:false,msg:'Zoom '+z+' é menor que o mínimo ('+min+'). Aproxime o mapa para enxergar os pontos com detalhe e gere o link de novo.'};
    return {ok:true,msg:'Link válido · zoom '+z+' · '+lat.toFixed(5)+', '+lon.toFixed(5)};
  }
  function prefill(){for(const f of definition.campos){if(f.preenchimento&&(answers[f.chave]==null||answers[f.chave]==='')){const value=source(f.preenchimento.origem);
    if(value==null)continue;
    if(f.validacao==='permalink'&&!permalinkCheck(String(value),f).ok)continue; // não preenche sozinho com zoom insuficiente
    answers[f.chave]=value;}}}
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
      if(f.validacao==='permalink'&&input&&f.tipo!=='file'){
        const hint=el('div','',box,'hint');
        const update=()=>{const t=String(answers[f.chave]??'').trim();if(!t){hint.textContent='Exigido: link do WME com zoom ≥ '+(f.zoomMinimo||15)+' (vê-se mais pontos e detalhes do mapa).';hint.className='hint';return;}const c=permalinkCheck(t,f);hint.textContent=(c.ok?'✓ ':'✗ ')+c.msg;hint.className='hint '+(c.ok?'ok':'bad');};
        update();input.addEventListener('input',update);
      }
      if(f.preenchimento){
        const isLink=f.preenchimento.origem==='wme.permalink';
        const captureButton=el('button',isLink?'Usar posição atual do mapa':'Preencher do WME / conta',box);captureButton.type='button';
        captureButton.onclick=()=>{
          if(isLink){
            if(!sdk){notice('SDK do WME indisponível. Cole o permalink manualmente.','error');return;}
            context=collect();summary.textContent=summarize();
            const min=f.zoomMinimo||15;
            if(!(Number.isFinite(context.zoom)&&context.zoom>=min)){notice('Zoom atual: '+(context.zoom??'desconhecido')+'. Aproxime o mapa até o zoom '+min+' ou mais e clique de novo.','error');return;}
            const c=permalinkCheck(context.permalink,f);if(!c.ok){notice(c.msg,'error');return;}
            changed(context.permalink);renderFields();notice('Permalink atualizado com a posição atual (zoom '+context.zoom+').','success');return;
          }
          const v=source(f.preenchimento.origem);if(v!=null){changed(v);renderFields();}else notice('Dado indisponível para este campo. Preencha manualmente.','error');
        };
      }
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
      if(!e&&text&&f.validacao==='permalink'){const c=permalinkCheck(text,f);if(!c.ok)e=c.msg;}
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
    for(const b of typeList.children)b.classList.toggle('active',b.dataset.slug===slug);log('Campos recebidos', {slug, quantidade: data.campos.length});notice(data.campos.length ? '' : 'A API retornou este formulário sem campos. Confira a migration no banco do servidor.', data.campos.length ? '' : 'error');updateBusy();
  }catch(e){restoring=false;definition=null;notice(e.message,'error');updateBusy();authLost(e);}}
  const AUTH_CODES=['email_nao_confirmado','token_desconhecido','token_invalido','dispositivo_revogado','nao_autenticado'];
  function authLost(e){if(AUTH_CODES.includes(e?.codigo)){setTimeout(()=>boot(),0);return true;}return false;}
  function syncSteps(){
    const current=({registro:0,pendente:1,ativo:3})[conn];
    stepsCard.hidden=!(conn==='registro'||conn==='pendente'||(conn==='ativo'&&!settings.hidden));
    stepItems.forEach((li,i)=>{li.classList.toggle('done',current!==undefined&&i<current);li.classList.toggle('current',current!==undefined&&i===current);});
  }
  function setConn(s,info){
    conn=s;if(info)pendingInfo=info;
    registerCard.hidden=s!=='registro';waitCard.hidden=s!=='pendente';layout.hidden=s!=='ativo';mineButton.hidden=s!=='ativo';
    if(s!=='ativo')mineCard.hidden=true;
    if(s==='pendente')waitText.textContent='Enviamos um link de confirmação para '+(pendingInfo?.emailMascarado||'seu e-mail')+'. Abra o e-mail e clique em “Confirmar e-mail” para liberar o envio de solicitações.';
    connText.textContent=({ativo:'Conectado.',pendente:'Aguardando a confirmação do e-mail.',registro:'Não conectado.',carregando:'Conectando…',erro:'Sem conexão com o servidor.'})[s]||'';
    retryButton.hidden=s!=='erro';disconnectButton.hidden=!token;if(s==='erro')settings.hidden=false;
    if(s==='registro'&&!overlay.hidden)setTimeout(()=>(mailInput.value?consentBox:mailInput).focus(),0);
    syncSteps();updateBusy();
  }
  function prefillNick(){let nick='';try{nick=sdk?.State?.getUserInfo?.()?.userName||'';}catch{}
    if(nick){nickInput.value=nick;nickInput.readOnly=true;nickHint.textContent='Detectado no WME.';}
    else if(!nickInput.value){nickHint.textContent='Não foi possível detectar automaticamente. Informe seu apelido do WME.';}}
  async function dropToken(){token='';await store.del(TOKEN_KEY);await store.del(LEGACY_KEY);identity=null;definition=null;forms=[];answers={};files={};revokePreviews();fieldArea.replaceChildren();typeList.replaceChildren();accountText.textContent='Desconectado';}
  function startPoll(){stopPoll();const until=Date.now()+20*60000;pollTimer=setInterval(()=>{if(overlay.hidden||conn!=='pendente'||Date.now()>until){stopPoll();return;}boot(true);},6000);}
  function stopPoll(){if(pollTimer){clearInterval(pollTimer);pollTimer=null;}}
  async function applyBootstrap(data){
    if(data.estado==='pendente'){identity=null;definition=null;forms=[];typeList.replaceChildren();accountText.textContent='Aguardando confirmação do e-mail';setConn('pendente',data.usuario);startPoll();return;}
    if(data.estado!=='ativo'||!data.usuario?.id||!Array.isArray(data.formularios))throw new Error('Resposta de bootstrap inválida.');
    stopPoll();clearTimeout(saveTimer);definition=null;answers={};files={};fieldArea.replaceChildren();identity=data.usuario;forms=data.formularios;
    accountText.textContent=(identity.nome||identity.wazeNickname||'Usuário')+' · '+(identity.wazeNickname||'');typeList.replaceChildren();
    for(const f of forms){const b=el('button',f.nome,typeList);b.dataset.slug=f.slug;b.onclick=()=>choose(f.slug);}
    setConn('ativo');settings.hidden=true;syncSteps();
    notice(forms.length?'Conectado. Escolha um formulário.':'Nenhum formulário disponível para sua conta.','success');
  }
  async function boot(silent=false){
    if(busy)return;busy=true;updateBusy();if(!silent)setConn('carregando');
    try{
      if(!token){setConn('registro');return;}
      await applyBootstrap(await api('GET','/bootstrap'));
    }catch(e){
      if(e.codigo==='token_desconhecido'||e.codigo==='token_invalido'){stopPoll();setConn('registro');}
      else if(e.codigo==='dispositivo_revogado'){stopPoll();await dropToken();setConn('registro');notice('A conexão anterior foi encerrada. Conecte novamente.','error');}
      else{if(!silent)setConn('erro');notice(e.message,'error');}
    }finally{busy=false;updateBusy();}
  }
  async function register(){
    if(busy)return;mailError.textContent='';nickError.textContent='';
    const email=mailInput.value.trim(),nick=nickInput.value.trim(),nome=nameInput.value.trim();
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){mailError.textContent='Informe um e-mail válido.';mailInput.focus();return;}
    if(!nick){nickError.textContent='Informe seu apelido no WME.';nickInput.focus();return;}
    if(!consentBox.checked){notice('Marque a concordância para continuar.','error');consentBox.focus();return;}
    busy=true;updateBusy();notice('');let again=false;
    try{
      if(!token){token=randomToken();await store.set(TOKEN_KEY,token);await store.del(LEGACY_KEY);}
      const data=await api('POST','/registrar',{email,wazeNickname:nick,nome:nome||undefined,scriptVersion:VERSION});
      setConn('pendente',data);startPoll();
      notice(data.emailEnviado===false?'Conta registrada, mas o e-mail não pôde ser enviado agora. Use “Reenviar e-mail”.':'E-mail de confirmação enviado.',data.emailEnviado===false?'error':'success');
    }catch(e){
      if(e.errors?.email)mailError.textContent=[].concat(e.errors.email).join(' ');
      if(e.errors?.wazeNickname)nickError.textContent=[].concat(e.errors.wazeNickname).join(' ');
      if(e.codigo==='token_ja_registrado')again=true;else notice(e.message,'error');
    }finally{busy=false;updateBusy();}
    if(again)await boot();
  }
  async function resend(){
    if(busy)return;busy=true;updateBusy();let again=false;
    try{const d=await api('POST','/verificacao/reenviar');
      if(d.estado==='ativo')again=true;
      else{setConn('pendente',d);notice(d.emailEnviado===false?'Não foi possível enviar o e-mail agora. Tente novamente em instantes.':'Novo e-mail enviado.',d.emailEnviado===false?'error':'success');}
    }catch(e){notice(e.codigo==='aguarde'?'Aguarde '+Math.max(1,Math.ceil((e.data?.aguardarSegundos||60)/60))+' min para reenviar o e-mail.':e.message,'error');authLost(e);}
    finally{busy=false;updateBusy();}
    if(again)await boot();
  }
  async function disconnect(confirmFirst=true){
    if(busy)return;
    if(confirmFirst&&!window.confirm('Desconectar este navegador do ToolboxWaze? Você precisará confirmar o e-mail novamente.'))return;
    busy=true;updateBusy();stopPoll();clearTimeout(saveTimer);
    try{if(token)await api('POST','/dispositivo/revogar');}catch(e){warn('Revogação no servidor falhou:',e.message);}
    await dropToken();setConn('registro');settings.hidden=true;syncSteps();notice('Desconectado deste navegador.');busy=false;updateBusy();
  }
  function requestUrl(item) {
    const candidate = item.url || item.link || (item.id != null ? '/solicitacoes/' + encodeURIComponent(String(item.id)) : null);
    if (!candidate) return null;
    try {
      const url = new URL(candidate, BASE);
      return url.origin === new URL(BASE).origin && url.protocol === 'https:' ? url.href : null;
    } catch { return null; }
  }
  async function loadMine() {
    mineCard.hidden = !mineCard.hidden;
    if (mineCard.hidden) return;
    mineList.replaceChildren(el('p', 'Carregando…', null, 'muted'));
    try {
      const data = await api('GET', '/solicitacoes');
      mineList.replaceChildren();
      if (!data.solicitacoes?.length) {
        el('p', 'Você ainda não enviou solicitações.', mineList, 'muted');
        return;
      }
      for (const item of data.solicitacoes) {
        const url = requestUrl(item);
        const row = el(url ? 'a' : 'div', null, mineList, 'mine-request');
        if (url) {
          row.href = url; row.target = '_blank'; row.rel = 'noopener noreferrer';
          row.title = 'Abrir solicitação #' + item.id + ' no ToolboxWaze';
        }
        el('strong', '#' + item.id, row);
        el('span', item.formulario || item.tipoLabel || 'Solicitação', row);
        el('span', item.statusLabel || item.status || '', row, 'muted');
        const date = new Date(item.criadaEm);
        el('small', Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString(), row);
        if (url) el('span', 'Abrir ↗', row, 'mine-open');
      }
    } catch (error) {
      mineList.replaceChildren(el('p', error.message, null, 'error-text')); authLost(error);
    }
  }
  function saveNow(){if(!identity||!definition)return Promise.resolve();clearTimeout(saveTimer);return store.set('tw3_draft_'+String(identity.id)+'_'+definition.slug,{answers:structuredClone(answers),context,updatedAt:Date.now()});}
  async function result(data){if(!data.solicitacao?.id)throw Object.assign(new Error('Resposta sem identificador de solicitação.'),{uncertain:true});
    unresolved=null;await store.del('tw3_pending');notice('Solicitação #'+data.solicitacao.id+' criada com sucesso.','success');
    if(data.solicitacao.url){try{const u=new URL(data.solicitacao.url,BASE);if(u.origin===BASE){const a=el('a',' Abrir no ToolboxWaze',status);a.href=u.href;a.target='_blank';a.rel='noopener noreferrer';}}catch{}}
    if(definition)await store.del(await draftKey());clearTimeout(saveTimer);answers={};files={};dirty=false;renderFields();draftNote.textContent='Enviado. Anexos e rascunho removidos.';
  }
  async function send(){if(busy||!identity||!definition||!definition.campos?.length||unresolved)return;
    renderFields();const errors=validate();if(Object.keys(errors).length){for(const [k,v]of Object.entries(errors))fieldError(k,v);notice('Revise os campos destacados.','error');fieldArea.querySelector('.bad')?.focus();return;}
    const key=uid();const payload={formulario:definition.slug,versaoFormulario:definition.versao,respostas:{},contexto:context,origem:'wme-userscript',scriptVersion:VERSION,clientRequestId:key};
    for(const f of definition.campos){if(visible(f)&&f.tipo!=='file'&&answers[f.chave]!==undefined)payload.respostas[f.chave]=f.tipo==='number'&&answers[f.chave]!==''?Number(answers[f.chave]):answers[f.chave];}
    const body=new FormData();body.append('payload',JSON.stringify(payload));for(const f of definition.campos){if(visible(f)&&f.tipo==='file')for(const file of files[f.chave]||[])body.append('anexos['+f.chave+'][]',file,file.name);}
    busy=true;unresolved={key,accountId:identity.id,createdAt:Date.now(),formulario:definition.slug};
    try{await store.set('tw3_pending',unresolved);updateBusy();notice('Enviando… Não feche o navegador.');const data=await api('POST','/solicitacoes',body);await result(data);}
    catch(e){
      if(e.status&&e.status>=400&&e.status<500&&e.status!==409){unresolved=null;await store.del('tw3_pending');}
      notice(e.message+(unresolved?'\nNão repita o envio. Consulte o resultado.':''),'error');
      for(const [k,v]of Object.entries(e.errors||{}))fieldError(k,Array.isArray(v)?v.join(' '):String(v));
      if(e.codigo==='formulario_desatualizado'&&definition){const slug=definition.slug;busy=false;await saveNow();await choose(slug);notice('O formulário foi atualizado. Revise os campos e envie novamente.','error');}
      else authLost(e);
    }
    finally{busy=false;updateBusy();}
  }
  async function checkPending(){if(busy||!unresolved)return;if(!identity||String(identity.id)!==String(unresolved.accountId)){notice('Conecte a conta usada no envio pendente.','error');return;}busy=true;updateBusy();try{
    const data=await api('GET','/envios/'+encodeURIComponent(unresolved.key));if(data.estado==='concluido')await result(data);
    else if(data.estado==='nao_criado'){unresolved=null;await store.del('tw3_pending');notice('O servidor confirmou que a solicitação não foi criada. Você pode enviar novamente.');}
    else notice('Envio ainda em processamento. Consulte novamente em instantes.');
  }catch(e){notice('Não foi possível confirmar: '+e.message,'error');}finally{busy=false;updateBusy();}}
  function open(){previousFocus=document.activeElement;overlay.hidden=false;if(!context&&sdk){context=collect();summary.textContent=summarize();}prefillNick();if(!identity)boot();closeButton.focus();updateBusy();}
  function close(){if(busy){notice('Aguarde a operação terminar.');return;}if(dirty)scheduleSave();overlay.hidden=true;stopPoll();previousFocus?.focus();}
  fab.onclick=open;closeButton.onclick=close;settingsButton.onclick=()=>{settings.hidden=!settings.hidden;syncSteps();};mineButton.onclick=loadMine;
  registerButton.onclick=register;verifyButton.onclick=async()=>{await boot(true);if(conn==='pendente')notice('Ainda aguardando a confirmação do e-mail.');};
  resendButton.onclick=resend;changeButton.onclick=()=>disconnect(false);disconnectButton.onclick=()=>disconnect(true);retryButton.onclick=()=>boot();
  refresh.onclick=()=>{if(dirty&&!window.confirm('Atualizar o contexto do WME? Campos já preenchidos serão preservados.'))return;capture();};
  copyButton.onclick=async()=>{try{await navigator.clipboard.writeText(JSON.stringify(context,null,2));notice('Contexto copiado.');}catch{notice('O navegador bloqueou a cópia.','error');}};
  sendButton.onclick=send;checkButton.onclick=checkPending;
  clearButton.onclick=async()=>{if(!definition||!window.confirm('Apagar o rascunho deste formulário?'))return;clearTimeout(saveTimer);await store.del(await draftKey());answers={};files={};dirty=false;prefill();renderFields();draftNote.textContent='Rascunho limpo.';};
  overlay.addEventListener('click',e=>{if(e.target===overlay)close();});
  function modalKeydown(event) {
    if (event.key === 'Escape') { event.preventDefault(); close(); return; }
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
      event.preventDefault();
      if (event.isComposing || event.repeat) return;
      const path = event.composedPath();
      if (path.includes(registerCard)) { register(); return; }
      if (path.includes(settings)) return;
      if (!sendButton.disabled) send();
      return;
    }
    if (event.key === 'Tab') {
      const nodes = Array.from(dialog.querySelectorAll('button,a,input,select,textarea'))
        .filter(n => !n.disabled && n.getClientRects().length);
      const first = nodes[0], last = nodes.at(-1), active = root.activeElement;
      if (!nodes.length) { event.preventDefault(); return; }
      if (event.shiftKey && (active === first || !dialog.contains(active))) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && (active === last || !dialog.contains(active))) {
        event.preventDefault(); first.focus();
      }
    }
  }
  function shieldKeyboard(event) {
    if (overlay.hidden) return;
    // A digitacao normal mantem sua acao padrao; apenas a propagacao e bloqueada.
    if (!dialog.contains(root.activeElement) && event.type === 'keydown' && event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
      closeButton.focus();
    }
    if (event.type === 'keydown') modalKeydown(event);
    event.stopImmediatePropagation();
    event.stopPropagation();
  }
  for (const type of ['keydown', 'keypress', 'keyup']) {
    window.addEventListener(type, shieldKeyboard, {capture: true});
  }
  PAGE.twDiagnostico=()=>{const d={version:VERSION,sdk:!!sdk,conn,connected:!!identity,form:definition?.slug,selected:selection().length,pending:!!unresolved,modalOpen:!overlay.hidden};log(d);return d;};
  // Teste rápido da API pelo console (F12): twTestarApi()
  PAGE.twTestarApi=async()=>{try{const d=await api('GET','/bootstrap');log('API respondeu JSON:',d);return d;}catch(e){warn('API falhou:',e.status||'',e.codigo||'',e.message);return e.message;}};
  log('Interface carregada',VERSION);updateBusy();
  try{for(let i=0;i<200;i++){if(PAGE.SDK_INITIALIZED&&typeof PAGE.getWmeSdk==='function')break;if(i===199)throw new Error('SDK indisponível após 60 segundos.');await new Promise(r=>setTimeout(r,300));}
    await PAGE.SDK_INITIALIZED;sdk=PAGE.getWmeSdk({scriptId:'toolboxwaze-solicitacoes',scriptName:'ToolboxWaze — Solicitações'});
    if(!sdk.State.isReady())await sdk.Events.once({eventName:'wme-ready'});context=collect();summary.textContent=summarize();prefillNick();
    sdk.Events.on({eventName:'wme-selection-changed',eventHandler:()=>{const n=selection().length;fab.textContent='Toolbox · Solicitações'+(n?' ('+n+')':'');if(!overlay.hidden)contextNotice.textContent='A seleção pode ter mudado. Use Atualizar para recapturar.';}});log('SDK pronto');
  }catch(e){summary.textContent='SDK indisponível. Preenchimento manual disponível.';warn(e.message);}
})();