// Verificação da INTERFACE com a API real (CI): entra com cada função, abre todos os menus,
// separadores, secções das Definições, o perfil e os avisos. Falha se houver erro de JavaScript
// ou resposta 5xx da API. Capturas e relatório em e2e-report/.
import {chromium} from 'playwright';
import fs from 'node:fs';

const BASE=process.env.CIS_E2E_URL||'http://127.0.0.1:4175';
const PASS=process.env.CIS_E2E_PASSWORD;
const ROLES=(process.env.CIS_E2E_ROLES||'administrador,gestor,agente').split(',');
const OUT='e2e-report';fs.mkdirSync(OUT,{recursive:true});
const report={inicio:new Date().toISOString(),funcoes:{}};let problems=0;
const slug=s=>s.normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/[^a-z0-9]+/gi,'-').toLowerCase();

const RH_GROUPS={'Visão geral de RH':['Visão geral de RH'],Pessoas:['Colaboradores','Contratos de trabalho','Adendas','Férias'],
 'Remuneração':['Folha salarial','Indemnizações','Prestações de indemnização'],'Relatórios e regras':['Relatórios de RH','Definições de RH','Integrações de RH']};
const browser=await chromium.launch();
for(const role of ROLES){
 const r={paginas:[],erros_js:[],api_5xx:[],api_4xx:[],avisos_ecra:[]};report.funcoes[role]=r;
 const ctx=await browser.newContext({viewport:{width:1440,height:950},locale:'pt-PT'});
 const pg=await ctx.newPage();
 pg.on('pageerror',e=>r.erros_js.push(String(e).slice(0,300)));
 pg.on('console',m=>{if(m.type()==='error'&&!/Failed to load resource|dashboard|streamlit/i.test(m.text()))r.erros_js.push('console: '+m.text().slice(0,300));});
 pg.on('response',res=>{const u=res.url();if(!u.includes('/api/'))return;const s=res.status();
  if(s>=500&&s!==503&&!/streamlit/.test(u))r.api_5xx.push(s+' '+res.request().method()+' '+u.replace(BASE,''));
  else if(s>=400&&s!==401&&s!==403&&s!==503)r.api_4xx.push(s+' '+res.request().method()+' '+u.replace(BASE,''));});
 const shot=async name=>{await pg.screenshot({path:`${OUT}/${slug(role)}-${slug(name)}.png`,fullPage:true}).catch(()=>{});};
 const settle=async()=>{await pg.waitForLoadState('networkidle',{timeout:15000}).catch(()=>{});await pg.waitForTimeout(500);};
 const closeDialogs=async()=>{
  for(let i=0;i<3;i++){
   if(await pg.locator('.cis-ld').count()){await pg.getByRole('button',{name:'Agora não'}).click().catch(()=>{});await pg.waitForTimeout(300);}
   if(await pg.locator('dialog[open]').count()){await pg.keyboard.press('Escape');await pg.waitForTimeout(300);}
  }};
 const screenErrors=async where=>{
  const txt=await pg.locator('[role=alert], .cis-co-alert.bad, .cis-error').allInnerTexts().catch(()=>[]);
  for(const t of txt){const s=t.trim();if(s&&!/instalad|não tem permissão|Sem permissão/i.test(s))r.avisos_ecra.push(where+': '+s.slice(0,200));}
 };
 // Entrada
 await pg.goto(BASE+'/');
 await pg.fill('#email',`verificacao.${role}@cis.test`);await pg.fill('#password',PASS);
 await pg.locator('form button[type=submit]').click();
 for(let i=0;i<80;i++){await pg.waitForTimeout(500);
  if(await pg.locator('.cis-boot-skip').count())await pg.locator('.cis-boot-skip').click().catch(()=>{});
  if(await pg.locator('.cis-layout').count()&&!(await pg.locator('.cis-boot').count()))break;}
 if(!(await pg.locator('.cis-side').count())){r.erros_js.push('Não entrou no sistema');await shot('entrada');problems++;await ctx.close();continue;}
 await settle();await closeDialogs();await shot('00-entrada');
 // Menus
 const menus=await pg.locator('.cis-side nav button').allInnerTexts();
 for(const label of menus.map(s=>s.trim()).filter(Boolean)){
  await pg.locator('.cis-side nav button',{hasText:label}).first().click();await settle();await closeDialogs();
  r.paginas.push(label);await screenErrors(label);await shot(label);
  if(label==='Recursos Humanos'){
   const rh=pg.getByRole('button',{name:'Recursos Humanos',exact:true});
   const expected=['Visão geral de RH','Colaboradores','Contratos de trabalho','Adendas','Férias','Indemnizações',
    'Prestações de indemnização','Folha salarial','Relatórios de RH','Definições de RH','Integrações de RH'];
   for(const name of expected){
    // O menu mostra só os grupos de RH; as páginas de cada grupo são separadores no conteúdo.
    const group=Object.entries(RH_GROUPS).find(([,pages])=>pages.includes(name))?.[0]??name;
    const side=pg.locator('.cis-side nav').getByRole('button',{name:group,exact:true});
    if(await side.count()!==1){r.erros_js.push('Grupo de RH em falta no menu: '+group);continue;}
    const tab=pg.locator('main .rh-tabs').getByRole('tab',{name,exact:true});
    const item={click:async()=>{await side.click();await pg.waitForTimeout(200);if(group!==name){
     if(await tab.count()!==1)throw new Error('Separador de RH em falta: '+name);await tab.click();}}};
    const api=pg.waitForResponse(res=>res.url().includes('/api/cis/rh/')&&res.request().method()==='GET',{timeout:15000}).catch(()=>null);
    try{await item.click();}catch(e){r.erros_js.push(String(e.message||e));continue;}
    const response=await api;
    if(!response?.ok())r.erros_js.push('RH '+name+': API sem sucesso'+(response?' ('+response.status()+')':''));
    await settle();await screenErrors('RH › '+name);await shot('rh-'+name);r.paginas.push('RH › '+name);
    if(await rh.getAttribute('aria-expanded')!=='true')await rh.click();
   }
  }
  // Separadores da página (ex.: Visão geral | Dashboard, Mutuários | Lista negra, avisos)
  const tabs=pg.locator('main [role=tablist]:not([aria-label="Tipo de aviso"]) [role=tab]');
  const nt=await tabs.count();
  for(let i=0;i<nt;i++){const t=(await tabs.nth(i).innerText()).trim();await tabs.nth(i).click().catch(()=>{});await settle();
   r.paginas.push(label+' › '+t);await screenErrors(label+' › '+t);await shot(label+'-'+t);}
  if(label==='Definições'){
   const secs=await pg.locator('.cis-settings-nav button').allInnerTexts();
   for(const s of secs.map(x=>x.trim()).filter(Boolean)){await pg.locator('.cis-settings-nav button',{hasText:s}).first().click();await settle();
    r.paginas.push('Definições › '+s);await screenErrors('Definições › '+s);await shot('definicoes-'+s);}
  }
 }
 // Perfil (conta e subscrição) e avisos
 await pg.locator('.cis-profile-open').click().catch(()=>{});await settle();r.paginas.push('Perfil');await shot('perfil');
 const subTab=pg.getByRole('tab',{name:/Subscrição/});
 if(await subTab.count()){await subTab.click();await settle();r.paginas.push('Perfil › Subscrição');await screenErrors('Subscrição');await shot('perfil-subscricao');}
 const av=pg.locator('.cis-top-right button',{hasText:'Avisos'});
 if(await av.count()){await av.click();await settle();
  for(const k of ['Lista negra','Pagamentos em atraso','Cobranças a vencer']){const b=pg.locator('.cis-notice-tabs button',{hasText:k});
   if(await b.count()){await b.click();await settle();r.paginas.push('Avisos › '+k);await shot('avisos-'+k);}}
  await pg.keyboard.press('Escape');}
 const n=r.erros_js.length+r.api_5xx.length;problems+=n;
 console.log(`${role}: ${r.paginas.length} ecrãs · erros JS ${r.erros_js.length} · API 5xx ${r.api_5xx.length} · API 4xx ${r.api_4xx.length} · alertas no ecrã ${r.avisos_ecra.length}`);
 for(const e of [...r.erros_js,...r.api_5xx])console.log('   ✗ '+e);
 for(const e of [...new Set(r.api_4xx)].slice(0,15))console.log('   · '+e);
 for(const e of [...new Set(r.avisos_ecra)].slice(0,15))console.log('   ! '+e);
 await ctx.close();
}
await browser.close();
report.fim=new Date().toISOString();report.problemas=problems;
fs.writeFileSync(`${OUT}/relatorio.json`,JSON.stringify(report,null,1));
console.log(problems?`INTERFACE COM ${problems} PROBLEMA(S)`:'INTERFACE SEM ERROS');
process.exit(problems?1:0);
