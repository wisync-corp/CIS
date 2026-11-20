// Fotografia de um documento do CIS (recibo, extracto) em JPEG, para anexar a mensagens.
import {toJpeg} from 'html-to-image';

const LIMIT=6_500_000; // a extensão aceita até ~7,5 MB em data URL
export async function documentImage(el:HTMLElement):Promise<string>{
 const opts={backgroundColor:'#ffffff',cacheBust:true,width:el.offsetWidth,height:el.offsetHeight,style:{margin:'0',transform:'none',boxShadow:'none'}};
 // Documentos longos (extractos) ficam grandes: baixa a resolução/qualidade até caber.
 for(const [pixelRatio,quality] of [[2,0.9],[1.6,0.85],[1.3,0.8],[1,0.75]] as const){
  const url=await toJpeg(el,{...opts,pixelRatio,quality});
  if(url.length<LIMIT)return url;
 }
 throw new Error('O documento é demasiado grande para enviar como imagem; reduza o período ou use o PDF.');
}
export const fileName=(s:string)=>s.replace(/[^\w.-]+/g,'_').replace(/_+/g,'_')+'.jpg';
