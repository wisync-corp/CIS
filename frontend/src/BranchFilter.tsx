import {Building2} from 'lucide-react';
import {useBranches} from './branches';

/** Filtro de agência para quem vê todas (o administrador). Quem está numa agência não o vê. */
export default function BranchFilter({token,value,onChange}:{token:string;value:string;onChange:(v:string)=>void}){
 const b=useBranches(token);
 if(b.minha!==null||b.agencias.length<2)return null;
 return <label className="cis-branch-filter"><Building2 size={14}/> Agência
  <select value={value} onChange={e=>onChange(e.target.value)} aria-label="Agência"><option value="">Todas</option>{b.agencias.map(a=><option key={a.id} value={a.id}>{a.nome}</option>)}</select></label>;
}
