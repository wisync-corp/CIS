export const themeOptions = [
 {id:'fintech',name:'CIS Branco',subtitle:'Branco · Roxo · Verde',description:'Menu branco e acções em roxo da marca. Máxima legibilidade.',swatches:['#FFFFFF','#452D80','#F7F6FA']},
 {id:'banking',name:'CIS Verde',subtitle:'Verde-claro · Verde escuro',description:'Menu verde-claro e acções em verde da marca.',swatches:['#F2F8EA','#2A520B','#F7FAF3']},
 {id:'premium',name:'CIS Lilás',subtitle:'Lilás · Roxo profundo',description:'Menu lilás e acções em roxo profundo, com o contraste mais forte.',swatches:['#EFEBF8','#342163','#F7F5FB']},
] as const;
export type ThemeId=(typeof themeOptions)[number]['id'];
const STORAGE_KEY='cis_ui_theme_v1';
export function getTheme():ThemeId {
 try{
  const stored=localStorage.getItem(STORAGE_KEY);
  return themeOptions.some(theme=>theme.id===stored)?stored as ThemeId:'fintech';
 }catch{return 'fintech';}
}
export function applyTheme(theme:ThemeId):void {
 document.documentElement.dataset.theme=theme;
 try{localStorage.setItem(STORAGE_KEY,theme);}catch{/* Browser pode impedir gravação local. */}
}
