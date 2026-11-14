export const themeOptions = [
 {id:'banking',name:'Bancário',subtitle:'Azul-marinho · Azul corporativo',description:'Clássico, claro e institucional.',swatches:['#14263D','#2563EB','#F8FAFC']},
 {id:'fintech',name:'Fintech',subtitle:'Azul-petróleo · Turquesa',description:'Moderno, equilibrado e distinto.',swatches:['#102A43','#087E8B','#F7F9FB']},
 {id:'premium',name:'Premium',subtitle:'Grafite · Azul eléctrico',description:'Minimalista e orientado a dados.',swatches:['#171E2B','#3B82F6','#F4F6F9']},
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
