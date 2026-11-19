import {Fragment,type ReactNode} from 'react';

export type TemplateVar={nome:string;grupo:string;descricao:string;exemplo:string};
export type PlanRow={prestacao:string;vencimento:string;capital:string;juros:string;valor:string};
export type ContractValues={contrato:string;valores:Record<string,string>;plano:PlanRow[]};

const TOKEN=/(\*\*[^*]+\*\*|\{[^{}\n]+\}|\[\[[^\]\n]+\]\])/g;

/** Variáveis usadas num texto e as desconhecidas. */
export function usedVariables(body:string,known:string[]){
 const used=[...new Set([...body.matchAll(/\{([^{}\n]+)\}/g)].map(m=>m[1].trim()))];
 return {used,unknown:used.filter(v=>!known.includes(v))};
}

function inline(text:string,vals:Record<string,string>,mode:'preview'|'print',key='i'):ReactNode[]{
 return text.split(TOKEN).filter(p=>p!=='').map((p,i)=>{
  const k=key+'-'+i;
  if(p.startsWith('**')&&p.endsWith('**'))return <strong key={k}>{inline(p.slice(2,-2),vals,mode,k)}</strong>;
  if(p.startsWith('[[')&&p.endsWith(']]'))return <span key={k} className="cis-ct-blank" title={p.slice(2,-2)}>{mode==='preview'?p.slice(2,-2):' '}</span>;
  if(p.startsWith('{')&&p.endsWith('}')){
   const name=p.slice(1,-1).trim();const v=vals[name];
   if(v!==undefined&&v!=='')return <Fragment key={k}>{v}</Fragment>;
   return mode==='preview'?<mark key={k} className={'cis-ct-var'+(v===undefined?' unknown':' empty')} title={v===undefined?'Variável desconhecida':'Sem valor neste contrato'}>{p}</mark>
    :<span key={k} className="cis-ct-blank">{' '}</span>;
  }
  return <Fragment key={k}>{p}</Fragment>;
 });
}

/** Documento do contrato a partir do modelo: # título, ## cláusula, ### subtítulo, **negrito**, {Variável}, [[campo a preencher]]. */
export function ContractBody({body,values,plano,mode='preview',companyName}:{body:string;values:Record<string,string>;plano:PlanRow[];mode?:'preview'|'print';companyName?:string}){
 const out:ReactNode[]=[];
 body.replace(/\r\n/g,'\n').split('\n').forEach((raw,i)=>{
  const line=raw.trimEnd();const t=line.trim();
  if(t==='')return;
  if(t==='{Plano de Pagamentos}'){out.push(<table key={i} className="cis-ct-plan"><thead><tr><th>Prestação</th><th>Vencimento</th><th className="num">Capital (MT)</th><th className="num">Juros (MT)</th><th className="num">Valor da prestação (MT)</th></tr></thead>
   <tbody>{plano.length?plano.map(r=><tr key={r.prestacao+r.vencimento}><td>{r.prestacao}</td><td>{r.vencimento}</td><td className="num">{r.capital}</td><td className="num">{r.juros}</td><td className="num">{r.valor}</td></tr>)
    :<tr><td colSpan={5}>Sem prestações.</td></tr>}</tbody></table>);return;}
  if(t==='{Assinaturas}'){out.push(<div key={i} className="cis-ct-sign"><span>{companyName||'Pela empresa'}</span><span>O Mutuário</span><span>Testemunha</span></div>);return;}
  if(t.startsWith('### '))return void out.push(<p key={i} className="cis-ct-sub">{inline(t.slice(4),values,mode,'l'+i)}</p>);
  if(t.startsWith('## '))return void out.push(<h2 key={i} className="cis-ct-clause">{inline(t.slice(3),values,mode,'l'+i)}</h2>);
  if(t.startsWith('# '))return void out.push(<h1 key={i} className="cis-ct-title">{inline(t.slice(2),values,mode,'l'+i)}</h1>);
  const item=/^[a-z]\)\s/.test(t);
  out.push(<p key={i} className={item?'cis-ct-item':t==='E'?'cis-ct-center':''}>{inline(t,values,mode,'l'+i)}</p>);
 });
 return <div className="cis-ct-doc">{out}</div>;
}
