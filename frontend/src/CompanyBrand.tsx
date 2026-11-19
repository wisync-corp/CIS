import type {CompanyProfile} from './company';

/** Cabeçalho dos documentos: logótipo e identificação da empresa (Definições › Informações gerais).
 *  Sem logótipo carregado não aparece imagem nenhuma. */
export default function CompanyBrand({company}:{company:CompanyProfile|null}){
 const c=company;const name=c?.nome_empresa?.trim();const logo=c?.logotipo;
 if(!logo&&!name)return null;
 const contacts=[c?.contacto_principal,c?.contacto_alternativo,c?.email].filter(Boolean).join(' · ');
 const place=[c?.morada,c?.distrito,c?.provincia].filter(Boolean).join(', ');
 return <span className="cis-doc-brand">
  {logo&&<img src={logo} alt={name||'Logótipo da empresa'} className="own"/>}
  {name&&<span className="who"><b>{name}</b>
   {(c?.nuit||c?.licenca_bm)&&<small>{[c?.nuit&&`NUIT ${c.nuit}`,c?.licenca_bm&&`Licença ${c.licenca_bm}`].filter(Boolean).join(' · ')}</small>}
   {place&&<small>{place}</small>}{contacts&&<small>{contacts}</small>}</span>}
 </span>;
}

export const PoweredBy=()=><span className="cis-doc-powered">Powered by <b>WiSync</b></span>;
