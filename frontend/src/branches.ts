// Lista de agências (para filtros e etiquetas). «minha» = agência do utilizador (null = todas).
import {useEffect,useState} from 'react';
const API=import.meta.env.VITE_API_URL||'/api';
export type BranchLite={id:number;codigo:string;nome:string;cidade:string|null;principal:number|boolean};
export function useBranches(token:string){
 const [s,setS]=useState<{agencias:BranchLite[];minha:number|null}>({agencias:[],minha:null});
 useEffect(()=>{const c=new AbortController();
  fetch(API+'/cis/agencias/lista',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(r=>r.ok?r.json():null).then(j=>{if(j)setS({agencias:j.agencias??[],minha:j.minha??null});}).catch(()=>{});
  return()=>c.abort();},[token]);
 return s;
}
