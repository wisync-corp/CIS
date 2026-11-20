// Envio do recibo ao mutuário: WhatsApp Web (com a imagem do recibo, pela extensão «CIS WhatsApp»),
// WhatsApp sem extensão (texto + imagem descarregada para anexar), partilha do sistema, e-mail ou cópia.
// Tudo o que sai fica no registo de mensagens (cis_message_log).
import {useEffect,useMemo,useState,type RefObject} from 'react';
import {toJpeg} from 'html-to-image';
import {CheckCircle2,Copy,Download,Loader2,Mail,MessageCircle,Send,Share2,AlertCircle} from 'lucide-react';
import {onWa,waState,waSend,jobStatus,getShowPref,pushToast} from './waBridge';

const API=import.meta.env.VITE_API_URL||'/api';
type Props={token:string;target:RefObject<HTMLElement|null>;numero:string;contrato:string;valor:string;data:string|null;prestacao:string|null;
 saldo:string|null;cliente:{nome:string;cliente_id?:number|null;telefone?:string|null;email?:string|null};empresa:string};

const money=(v:string|null)=>v==null?'—':new Intl.NumberFormat('pt-PT',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v))+' MT';
const dt=(v:string|null)=>v?v.slice(0,10).split('-').reverse().join('/'):'—';
const digits=(v:string)=>{let d=v.replace(/\D+/g,'').replace(/^00/,'');if(d.length===9&&/^[82]/.test(d))d='258'+d;return d;};

export default function ReceiptSend(p:Props){
 const first=(p.cliente.nome||'').trim().split(/\s+/)[0]||'';
 const initial=useMemo(()=>[
  `Olá ${first}, 👋`,
  `Confirmamos o recebimento de *${money(p.valor)}* em ${dt(p.data)}, referente ao contrato *${p.contrato}*${p.prestacao?`, prestação ${p.prestacao}`:''}.`,
  `Recibo n.º *${p.numero}*.`,
  ...(p.saldo!=null?[Number(p.saldo)>0?`Saldo em dívida: ${money(p.saldo)}.`:'O contrato não tem saldo em dívida. ✅']:[]),
  '',`Obrigado pela confiança.${p.empresa?`\n${p.empresa}`:''}`,
 ].join('\n'),[p.numero]);// eslint-disable-line react-hooks/exhaustive-deps
 const [phone,setPhone]=useState(p.cliente.telefone??'');
 const [text,setText]=useState(initial);
 const [busy,setBusy]=useState('');const [error,setError]=useState('');const [jobId,setJobId]=useState<string|null>(null);
 const [,tick]=useState(0);useEffect(()=>onWa(()=>tick(v=>v+1)),[]);
 useEffect(()=>{setPhone(p.cliente.telefone??'');setText(initial);setJobId(null);setError('');},[p.numero]);// eslint-disable-line react-hooks/exhaustive-deps
 const tel=digits(phone);const telOk=tel.length>=11;
 const wa=waState();const status=jobId?jobStatus(jobId):undefined;
 const fileName=p.numero.replace(/[^\w.-]+/g,'_')+'.jpg';

 async function image(){
  const el=p.target.current;if(!el)throw new Error('Recibo não encontrado no ecrã.');
  return toJpeg(el,{quality:0.92,pixelRatio:2,backgroundColor:'#ffffff',cacheBust:true,width:el.offsetWidth,height:el.offsetHeight,
   style:{margin:'0',transform:'none',boxShadow:'none'}});
 }
 async function log(canal:'whatsapp'|'copia',estado?:'aberta'){
  try{await fetch(API+'/cis/mensagens/registo',{method:'POST',headers:{Authorization:'Bearer '+p.token,Accept:'application/json','Content-Type':'application/json'},
   body:JSON.stringify({contrato:p.contrato,cliente_id:p.cliente.cliente_id??null,telefone:telOk?tel:null,canal,estado,detalhe:'Recibo '+p.numero,mensagem:text})});}catch{/* registo opcional */}
 }
 async function run(kind:string,f:()=>Promise<void>){setBusy(kind);setError('');try{await f();}catch(e){setError(e instanceof Error?e.message:'Não foi possível concluir.');}finally{setBusy('');}}

 const sendExtension=()=>run('ext',async()=>{
  const dataUrl=await image();const id='rec-'+p.numero+'-'+Date.now();
  waSend([{id,phone:tel,text,meta:{contrato:p.contrato,cliente:p.cliente.nome,cliente_id:p.cliente.cliente_id??null}}],getShowPref(),{name:fileName,type:'image/jpeg',dataUrl});
  setJobId(id);
 });
 const download=()=>run('img',async()=>{const a=document.createElement('a');a.href=await image();a.download=fileName;a.click();});
 const openWa=()=>run('wa',async()=>{
  const w=window.open('https://web.whatsapp.com/send?phone='+tel+'&text='+encodeURIComponent(text),'cis-whatsapp-web');if(w){try{w.opener=null;}catch{/* */}}
  await log('whatsapp','aberta');pushToast('info','WhatsApp aberto com a mensagem. Anexe a imagem do recibo descarregada e envie.');
 });
 const canShareFiles=typeof navigator!=='undefined'&&'canShare' in navigator;
 const share=()=>run('share',async()=>{
  const blob=await (await fetch(await image())).blob();const file=new File([blob],fileName,{type:'image/jpeg'});
  if(!navigator.canShare?.({files:[file]}))throw new Error('Este navegador não permite partilhar ficheiros. Use «Descarregar imagem».');
  try{await navigator.share({files:[file],text,title:'Recibo '+p.numero});await log('copia');}
  catch(e){if((e as DOMException)?.name!=='AbortError')throw e;}
 });
 const copy=()=>run('copy',async()=>{await navigator.clipboard.writeText(text);pushToast('ok','Mensagem copiada.');});
 const mail=()=>{window.location.href='mailto:'+encodeURIComponent(p.cliente.email??'')+'?subject='+encodeURIComponent('Recibo '+p.numero+' · '+(p.empresa||'Pagamento'))+'&body='+encodeURIComponent(text.replace(/\*/g,''));};

 return <section className="cis-receipt-send" aria-label="Enviar recibo ao mutuário">
  <div className="cis-receipt-send-head"><Send size={17}/><div><strong>Enviar recibo ao mutuário</strong><small>{p.cliente.nome||'Mutuário'}{p.cliente.telefone?'':' · sem contacto na ficha: indique o número'}</small></div></div>
  <div className="cis-receipt-send-grid">
   <label>Número de WhatsApp<input inputMode="tel" value={phone} placeholder="84 000 0000" aria-invalid={!!phone&&!telOk} onChange={e=>{setPhone(e.target.value);setJobId(null);}}/>
    <small>{telOk?'+'+tel:phone?'Número incompleto.':'Número de telemóvel do mutuário.'}</small></label>
   <label>Mensagem<textarea rows={6} value={text} onChange={e=>setText(e.target.value)}/></label>
  </div>
  <div className="cis-receipt-send-actions">
   {wa.available
    ?<button type="button" className="primary" disabled={!telOk||!text.trim()||!!busy||status==='a_enviar'} onClick={()=>void sendExtension()}>
      {busy==='ext'||status==='a_enviar'?<Loader2 size={15} className="spin"/>:<MessageCircle size={15}/>} {status==='a_enviar'?'A enviar…':'Enviar pelo WhatsApp com o recibo'}</button>
    :<button type="button" className="primary" disabled={!telOk||!text.trim()||!!busy} onClick={()=>void openWa()}><MessageCircle size={15}/> Abrir WhatsApp com a mensagem</button>}
   <button type="button" disabled={!!busy} onClick={()=>void download()}>{busy==='img'?<Loader2 size={15} className="spin"/>:<Download size={15}/>} Descarregar imagem</button>
   {canShareFiles&&<button type="button" disabled={!!busy} onClick={()=>void share()}><Share2 size={15}/> Partilhar…</button>}
   {p.cliente.email&&<button type="button" onClick={mail}><Mail size={15}/> E-mail</button>}
   <button type="button" disabled={!!busy} onClick={()=>void copy()}><Copy size={15}/> Copiar mensagem</button>
  </div>
  {status==='enviada'&&<p className="ok"><CheckCircle2 size={15}/> Recibo enviado pelo WhatsApp e registado nas mensagens.</p>}
  {status==='falhada'&&<p className="err"><AlertCircle size={15}/> O WhatsApp Web não conseguiu enviar. Veja o aviso e tente de novo.</p>}
  {!wa.available&&<p className="hint">Com a extensão «CIS WhatsApp» activa, o recibo segue como imagem automaticamente. Sem ela, abra o WhatsApp e anexe a imagem descarregada.</p>}
  {error&&<p className="err" role="alert"><AlertCircle size={15}/> {error}</p>}
 </section>;
}
