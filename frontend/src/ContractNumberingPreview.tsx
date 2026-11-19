import {useEffect,useState} from 'react';
import {Loader2,AlertTriangle,CheckCircle2,Terminal} from 'lucide-react';

const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
type Resumo={ano:number;contratos:number;primeiro:string;ultimo:string;alterar:number};
type Data={activa:boolean;instalado:boolean;exemplo:string;prefixo_gravado:string;digitos_gravados:number;resumo:Resumo[];amostra:{actual:string;novo:string;desembolso:string}[];sem_data:string[];total:number;a_alterar:number};
const dt=(s:string)=>s?s.split('-').reverse().join('/'):'—';

/** Pré-visualização da numeração anual com o prefixo e os dígitos escritos no formulário. */
export default function ContractNumberingPreview({token,prefixo,digitos,dirty,recibo}:{token:string;prefixo:string;digitos:string;dirty:boolean;recibo?:string}){
 const [data,setData]=useState<Data|null>(null),[error,setError]=useState(''),[loading,setLoading]=useState(true);
 useEffect(()=>{const p=(prefixo||'').toUpperCase().trim();if(!/^[A-Z0-9]{1,10}$/.test(p)||!/^[3-6]$/.test(digitos)){setError('Prefixo: 1 a 10 letras ou números, sem espaços.');return;}
  const c=new AbortController();const t=setTimeout(()=>{setLoading(true);setError('');
   fetch(`${API}/cis/contratos/numeracao?prefixo=${encodeURIComponent(p)}&digitos=${digitos}`,{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
    .then(async r=>{const d=await r.json().catch(()=>({}));if(!r.ok)throw new Error(d.message||'Não foi possível pré-visualizar.');return d;}).then(setData)
    .catch(e=>{if(e.name!=='AbortError')setError(e.message);}).finally(()=>{if(!c.signal.aborted)setLoading(false);});},300);
  return ()=>{clearTimeout(t);c.abort();};},[token,prefixo,digitos]);
 if(error)return <div className="cis-co-alert bad"><AlertTriangle size={16}/><span>{error}</span></div>;
 if(!data)return <div className="cis-co-loading"><Loader2 size={16} className="cis-spin"/> A preparar a pré-visualização…</div>;
 return <div className={'cis-num'+(loading?' loading':'')}>
  <div className="cis-num-example"><span>Exemplo</span><b>{data.exemplo}</b><small>Prefixo · ano do desembolso · sequência que recomeça em 1 em cada ano</small>{recibo&&<><span className="r">Recibo</span><b>{`${recibo}-${data.exemplo.split('-')[1]}-${'1'.padStart(Number(digitos)||4,'0')}`}</b><small>pela data do pagamento</small></>}</div>
  {data.activa?<div className="cis-co-alert ok"><CheckCircle2 size={16}/><span>Numeração anual <b>activa</b>. Os novos contratos recebem o próximo número do ano do desembolso.{dirty||data.prefixo_gravado!==(prefixo||'').toUpperCase()||String(data.digitos_gravados)!==digitos?' Se mudar o prefixo ou os dígitos, só os novos contratos usam o formato novo até voltar a renumerar.':''}</span></div>
   :<div className="cis-co-alert warn"><AlertTriangle size={16}/><span>Ainda não aplicada. Guarde o prefixo e, na VPS, execute a renumeração de <b>todos os {data.total} contratos</b> (faz cópia de segurança antes e pede confirmação). Até lá, os novos contratos continuam com C-0000.</span></div>}
  <table className="cis-glossary-table cis-num-table"><thead><tr><th>Ano</th><th className="num">Contratos</th><th>Primeiro</th><th>Último</th></tr></thead>
   <tbody>{data.resumo.map(r=><tr key={r.ano}><td><b>{r.ano}</b></td><td className="num">{r.contratos}</td><td><code>{r.primeiro}</code></td><td><code>{r.ultimo}</code></td></tr>)}
   {data.resumo.length===0&&<tr><td colSpan={4}>Sem contratos.</td></tr>}</tbody></table>
  {data.amostra.length>0&&<details className="cis-num-sample"><summary>Ver exemplos ({data.a_alterar} contrato(s) mudam de número)</summary>
   <table className="cis-glossary-table"><thead><tr><th>Número actual</th><th>Novo número</th><th>Desembolso</th></tr></thead>
    <tbody>{data.amostra.map(a=><tr key={a.actual}><td><code>{a.actual}</code></td><td><code>{a.novo}</code></td><td>{dt(a.desembolso)}</td></tr>)}</tbody></table></details>}
  {data.sem_data.length>0&&<div className="cis-co-alert warn"><AlertTriangle size={16}/><span>{data.sem_data.length} contrato(s) sem data de desembolso mantêm o número actual: {data.sem_data.slice(0,8).join(', ')}{data.sem_data.length>8?'…':''}</span></div>}
  {!data.activa&&<div className="cis-num-cmd"><Terminal size={15}/><code>cd /opt/cis && git pull origin main && bash deploy/renumber-contracts.sh</code></div>}
  <p className="cis-num-note">O número antigo (ex.: C-0451) continua a funcionar na pesquisa, na ficha do contrato e no histórico.</p>
 </div>;
}
