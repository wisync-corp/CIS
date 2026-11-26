// Encargos do crédito em vigor (Definições › Encargos do crédito e › Cobrança e juros de mora).
import {useEffect,useState} from 'react';
const API=import.meta.env.VITE_API_URL||'/api';
export type CreditCharges={taxa_operacao:number;taxa_operacao_minima:number;taxa_renovacao:number;encargos_renovacao:'cobrar'|'capitalizar';
 renovacao_atraso_max_dias:number;finalidades:string[];mora:{automatica:boolean;descricao:string;regra:Record<string,unknown>|null}};
let cache:{token:string;at:number;data:CreditCharges}|null=null;
export function useCreditCharges(token:string){
 const [data,setData]=useState<CreditCharges|null>(cache&&cache.token===token&&Date.now()-cache.at<60000?cache.data:null);
 useEffect(()=>{if(data)return;const c=new AbortController();
  fetch(API+'/cis/encargos',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(r=>r.ok?r.json():null).then((v:CreditCharges|null)=>{if(v&&!c.signal.aborted){cache={token,at:Date.now(),data:v};setData(v);}}).catch(()=>{});
  return()=>c.abort();},[token,data]);
 return data;
}
