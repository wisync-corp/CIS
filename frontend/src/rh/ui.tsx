// Peças visuais partilhadas pelos ecrãs de Recursos Humanos (estado, datas, montantes, filtros).
import type {ReactNode} from 'react';
import './rh.css';

export type Tone='neutral'|'info'|'warn'|'ok'|'bad';
const STATES:Record<string,[string,Tone]>={
 pendente:['Pendente','warn'],alterado:['Alterado','info'],finalizado:['Finalizado','ok'],por_conferir:['Por conferir','bad'],
 aprovado:['Aprovado','ok'],rejeitado:['Rejeitado','bad'],activo:['Activo','ok'],inactivo:['Inactivo','neutral'],
 paga:['Paga','ok'],por_pagar:['Por pagar','warn'],vencida:['Vencida','bad'],
 aberto:['Mês aberto','info'],fechado:['Mês fechado','ok'],por_abrir:['Mês por abrir','neutral'],
};
export const stateLabel=(s:string)=>STATES[s]?.[0]??(s?s.charAt(0).toUpperCase()+s.slice(1):'—');

export function Badge({state,tone,children,title}:{state?:string;tone?:Tone;children?:ReactNode;title?:string}){
 const t=tone??STATES[state??'']?.[1]??'neutral';
 return <span className={'rh-badge rh-'+t} title={title}>{children??stateLabel(state??'')}</span>;
}

/** AAAA-MM-DD → DD/MM/AAAA (o resto fica como veio). */
export const date=(v?:string|null)=>{if(!v)return '—';const m=/^(\d{4})-(\d{2})-(\d{2})/.exec(v);return m?m[3]+'/'+m[2]+'/'+m[1]:v;};
const nf=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2,useGrouping:'always' as unknown as boolean});
export const money=(v?:string|number|null)=>v==null||v===''||!Number.isFinite(Number(v))?'—':nf.format(Number(v))+' MT';
/** Mês AAAA-MM → «Novembro de 2026». */
export const monthName=(p:string)=>{const [y,m]=p.split('-').map(Number);if(!y||!m)return p;
 const n=new Date(Date.UTC(y,m-1,1)).toLocaleDateString('pt-PT',{month:'long',timeZone:'UTC'});return n.charAt(0).toUpperCase()+n.slice(1)+' de '+y;};
/** Mês AAAA-MM abreviado → «Nov 2026». */
export const monthShort=(p:string)=>{const [y,m]=p.split('-').map(Number);if(!y||!m)return p;
 const n=new Date(Date.UTC(y,m-1,1)).toLocaleDateString('pt-PT',{month:'short',timeZone:'UTC'}).replace('.','');return n.charAt(0).toUpperCase()+n.slice(1)+' '+y;};
/** Dias de calendário entre duas datas, inclusive. */
export const daysBetween=(a?:string|null,b?:string|null)=>{if(!a||!b)return null;const d=(Date.parse(b)-Date.parse(a))/86400000;return Number.isFinite(d)&&d>=0?Math.round(d)+1:null;};

/** Nome do colaborador com código e empresa em linha secundária. */
export function Person({name,code,company,extra}:{name:string;code?:string;company?:string;extra?:ReactNode}){
 return <div className="rh-person"><strong>{name}</strong><small>{[code,company].filter(Boolean).join(' · ')}{extra}</small></div>;
}

/** Filtros por estado em forma de pastilhas, com contagem. */
export function Chips<T extends string>({items,value,onChange,label}:{items:{id:T;label:string;n?:number;tone?:Tone}[];value:T;onChange:(v:T)=>void;label:string}){
 return <div className="rh-chips" role="group" aria-label={label}>{items.map(i=><button key={i.id} type="button" aria-pressed={value===i.id}
  className={'rh-chip'+(i.tone?' rh-chip-'+i.tone:'')} onClick={()=>onChange(i.id)}>{i.label}{i.n!=null&&<span>{i.n}</span>}</button>)}</div>;
}

/** Barra de pesquisa de largura útil (substitui o campo estreito). */
export function SearchBar({value,onChange,onSubmit,placeholder}:{value:string;onChange:(v:string)=>void;onSubmit:()=>void;placeholder:string}){
 return <form className="rh-search" role="search" onSubmit={e=>{e.preventDefault();onSubmit();}}>
  <input type="search" aria-label={placeholder} placeholder={placeholder} value={value} onChange={e=>onChange(e.target.value)}/>
  <button type="submit">Pesquisar</button></form>;
}

/** Paginação simples. */
export function Pager({page,last,onPage}:{page:number;last:number;onPage:(p:number)=>void}){
 if(last<=1)return null;
 return <nav className="rh-pager" aria-label="Páginas"><button type="button" disabled={page<=1} onClick={()=>onPage(page-1)}>Anterior</button>
  <span>Página {page} de {last}</span><button type="button" disabled={page>=last} onClick={()=>onPage(page+1)}>Seguinte</button></nav>;
}

/** Estado vazio com explicação e, opcionalmente, uma acção. */
export function Empty({title,text,action}:{title:string;text?:string;action?:ReactNode}){
 return <div className="rh-empty"><strong>{title}</strong>{text&&<p>{text}</p>}{action}</div>;
}
