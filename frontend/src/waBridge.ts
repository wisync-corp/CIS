// Ligação do CIS à extensão «CIS WhatsApp» (envio pelo WhatsApp Web do operador).
// Vive fora dos ecrãs: o envio e os avisos continuam se o operador mudar de página.
const API=import.meta.env.VITE_API_URL||'/api';

export type WaJob={id:string;phone:string;text:string;meta:{contrato:string|null;cliente:string;cliente_id?:number|null;modelo_id?:number|''|null;grupo?:string|null;imagem?:string|null}};
export type WaImage={name:string;type:string;dataUrl:string};
export type WaResult={id:string;ok:boolean;detail:string|null;meta:WaJob['meta']|null};
export type Toast={id:number;kind:'ok'|'erro'|'info'|'login';text:string;sticky?:boolean};
type State={available:boolean;version:string|null;running:boolean;pendentes:number;aguardaLogin:boolean};

let state:State={available:false,version:null,running:false,pendentes:0,aguardaLogin:false};
let token='';
const results=new Map<string,'a_enviar'|'enviada'|'falhada'>();
const subs=new Set<()=>void>();
const toastSubs=new Set<(t:Toast[])=>void>();
let toasts:Toast[]=[];let toastSeq=1;
const emit=()=>subs.forEach(f=>f());

export function pushToast(kind:Toast['kind'],text:string,sticky=false){
 const t={id:toastSeq++,kind,text,sticky};toasts=[...toasts.slice(-4),t];toastSubs.forEach(f=>f(toasts));
 if(!sticky)setTimeout(()=>dismissToast(t.id),kind==='erro'?9000:5000);return t.id;
}
export function dismissToast(id:number){toasts=toasts.filter(t=>t.id!==id);toastSubs.forEach(f=>f(toasts));}
export function onToasts(f:(t:Toast[])=>void){toastSubs.add(f);f(toasts);return()=>{toastSubs.delete(f);};}
export function onWa(f:()=>void){subs.add(f);return()=>{subs.delete(f);};}
export const waState=()=>state;
export const jobStatus=(id:string)=>results.get(id);
export function setWaToken(t:string){token=t;}

function post(msg:Record<string,unknown>){window.postMessage({...msg,source:'cis-app'},location.origin);}

async function log(r:WaResult){
 if(!token||!r.meta)return;
 try{const res=await fetch(API+'/cis/mensagens/registo',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
  body:JSON.stringify({contrato:r.meta.contrato,cliente_id:r.meta.cliente_id??null,telefone:jobs.get(r.id)?.phone??null,canal:'whatsapp_web',
   estado:r.ok?'enviada':'falhada',detalhe:r.detail,modelo_id:r.meta.modelo_id||null,grupo:r.meta.grupo||null,mensagem:jobs.get(r.id)?.text??''})});
  if(!res.ok){const j=await res.json().catch(()=>({}));throw new Error((j as {message?:string}).message||'HTTP '+res.status);}
  const j=await res.json().catch(()=>({})) as {estado_registado?:boolean};
  if(j.estado_registado===false&&!warnedStatus){warnedStatus=true;pushToast('erro','Registo sem estado enviada/falhada: na VPS execute bash deploy/install-message-center.sh',true);}}
 catch(e){pushToast('erro',`Mensagem ${r.ok?'enviada':'não enviada'} para ${r.meta.cliente}, mas não ficou registada no CIS: ${e instanceof Error?e.message:'erro'}`,true);}
}
const jobs=new Map<string,WaJob>();
let warnedStatus=false;
let loginToast:number|null=null;

if(typeof window!=='undefined'){
 window.addEventListener('message',e=>{
  if(e.source!==window||e.origin!==location.origin)return;
  const d=e.data as {source?:string;type?:string;[k:string]:unknown};
  if(!d||d.source!=='cis-wa-ext')return;
  switch(d.type){
   case 'hello':state={...state,available:true,version:String(d.version??'')};emit();post({type:'status'});break;
   case 'status':state={...state,available:true,version:String(d.version??state.version??''),running:!!d.running,pendentes:Number(d.pendentes)||0,aguardaLogin:!!d.aguardaLogin};emit();break;
   case 'disconnected':state={...state,running:false};emit();break;
   case 'login':
    if(d.estado==='aguarda'){if(loginToast===null)loginToast=pushToast('login','WhatsApp Web sem sessão: leia o código QR no separador do WhatsApp. O envio continua depois do login.',true);}
    else{if(loginToast!==null){dismissToast(loginToast);loginToast=null;}
     pushToast(d.estado==='feito'?'ok':'erro',d.estado==='feito'?'Sessão do WhatsApp Web iniciada. A enviar…':'O login no WhatsApp Web não foi feito a tempo.');}
    break;
   case 'progress':results.set(String(d.id),'a_enviar');emit();break;
   case 'result':{const r=d as unknown as WaResult;results.set(r.id,r.ok?'enviada':'falhada');emit();
    const nome=r.meta?.cliente||'cliente';
    pushToast(r.ok?'ok':'erro',r.ok?`Mensagem enviada para ${nome}${r.detail?' · '+r.detail:''}`:`Não enviada para ${nome}: ${r.detail||'erro desconhecido'}`);
    void log(r);break;}
   case 'done':emit();break;
  }
 });
 // A ponte anuncia-se ao carregar; pedimos o estado caso a página tenha carregado antes.
 setTimeout(()=>post({type:'hello'}),300);
}

export function waSend(list:WaJob[],show:boolean,image?:WaImage|null){
 for(const j of list){jobs.set(j.id,{...j,meta:{...j.meta,imagem:image?.name??null}});results.set(j.id,'a_enviar');}
 emit();
 post({type:'send',show,minGap:8,maxGap:18,image:image??null,jobs:list.map(j=>({id:j.id,phone:j.phone,text:j.text,meta:j.meta}))});
 if(!state.running)pushToast('info',list.length>1?`A enviar ${list.length} mensagens pelo WhatsApp Web…`:'A enviar pelo WhatsApp Web…');
}
export function waStop(){post({type:'stop'});}
/** Pede à extensão que se anuncie (botão «Verificar novamente»). */
export function waCheck(){post({type:'hello'});}

export function getShowPref(){try{return localStorage.getItem('cis_wa_show')==='1';}catch{return false;}}
export function setShowPref(v:boolean){try{localStorage.setItem('cis_wa_show',v?'1':'0');}catch{/* sem armazenamento */}}
