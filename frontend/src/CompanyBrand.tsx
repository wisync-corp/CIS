import logoPrincipal from './assets/brand/cis-principal-claro.svg';
import type {CompanyProfile} from './company';

/** Cabeçalho dos documentos: logótipo e identificação da empresa (Configurações da empresa). */
export default function CompanyBrand({company}:{company:CompanyProfile|null}){
 const c=company;const name=c?.nome_empresa?.trim();
 const contacts=[c?.contacto_principal,c?.contacto_alternativo,c?.email].filter(Boolean).join(' · ');
 const place=[c?.morada,c?.distrito,c?.provincia].filter(Boolean).join(', ');
 return <span className="cis-doc-brand">
  <img src={c?.logotipo||logoPrincipal} alt={name||'CIS'} className={c?.logotipo?'own':''}/>
  {name&&<span className="who"><b>{name}</b>
   {(c?.nuit||c?.licenca_bm)&&<small>{[c?.nuit&&`NUIT ${c.nuit}`,c?.licenca_bm&&`Licença ${c.licenca_bm}`].filter(Boolean).join(' · ')}</small>}
   {place&&<small>{place}</small>}{contacts&&<small>{contacts}</small>}</span>}
 </span>;
}
