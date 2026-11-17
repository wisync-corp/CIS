export const themeOptions = [
 {id:'fintech',name:'CIS Roxo',subtitle:'Roxo profundo · Lima',description:'A identidade principal da marca: roxo dominante e destaque lima.',swatches:['#170F2B','#B5FA32','#F8F7FB']},
 {id:'banking',name:'CIS Verde',subtitle:'Roxo profundo · Verde',description:'Menu roxo com acções em verde da marca.',swatches:['#170F2B','#3B7311','#F8FAF5']},
 {id:'claro',name:'CIS Claro',subtitle:'Branco · Roxo · Verde',description:'Menu e entrada claros com o logótipo alternativo para fundo claro.',swatches:['#FFFFFF','#4F9A0F','#F8F7FB']},
 {id:'premium',name:'CIS Grafite',subtitle:'Grafite · Lima',description:'Escala de cinzentos da marca, sóbria e orientada a dados.',swatches:['#1E1E22','#B5FA32','#F5F5F7']},
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
