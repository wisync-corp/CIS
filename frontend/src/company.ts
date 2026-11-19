import {useEffect,useState} from 'react';

const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
export type CompanyProfile={nome_empresa:string;nuit:string;licenca_bm:string;inicio_actividade:string;capital_inicial:string;funcionarios:string;
 provincia:string;distrito:string;morada:string;contacto_principal:string;contacto_alternativo:string;email:string;proprietario:string;logotipo:string};

let cache:CompanyProfile|null=null;let pending:Promise<CompanyProfile|null>|null=null;
const listeners=new Set<(c:CompanyProfile|null)=>void>();

export function setCompanyProfile(c:CompanyProfile|null){cache=c;listeners.forEach(l=>l(c));}

export function loadCompanyProfile(token:string):Promise<CompanyProfile|null>{
 if(cache)return Promise.resolve(cache);
 if(!pending)pending=fetch(API+'/cis/configuracoes',{headers:{Authorization:'Bearer '+token,Accept:'application/json'}})
  .then(r=>r.ok?r.json():null).then(d=>{const c=d?.perfil??null;if(c)setCompanyProfile(c);return c;}).catch(()=>null).finally(()=>{pending=null;});
 return pending;
}

/** Perfil da empresa para cabeçalhos de documentos (cache partilhada). */
export function useCompanyProfile(token:string){
 const [c,setC]=useState<CompanyProfile|null>(cache);
 useEffect(()=>{listeners.add(setC);loadCompanyProfile(token).then(setC);return ()=>{listeners.delete(setC);};},[token]);
 return c;
}
