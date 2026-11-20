// Documentos do CIS (recibo, extracto) em PDF A4 para anexar às mensagens.
import {toCanvas} from 'html-to-image';
import {jsPDF} from 'jspdf';

const LIMIT=6_500_000; // a extensão aceita até ~7,5 MB em data URL

function toBase64(buf:ArrayBuffer){
 const bytes=new Uint8Array(buf);let bin='';
 for(let i=0;i<bytes.length;i+=0x8000)bin+=String.fromCharCode(...bytes.subarray(i,i+0x8000));
 return btoa(bin);
}

/** PDF A4 (retrato) do elemento, partido em páginas; devolve data:application/pdf;base64,… */
export async function documentPdf(el:HTMLElement,title:string):Promise<string>{
 const opts={backgroundColor:'#ffffff',cacheBust:true,width:el.offsetWidth,height:el.offsetHeight,style:{margin:'0',transform:'none',boxShadow:'none'}};
 for(const [pixelRatio,quality] of [[2,0.9],[1.6,0.85],[1.3,0.8]] as const){
  const canvas=await toCanvas(el,{...opts,pixelRatio});
  const pdf=new jsPDF({orientation:'portrait',unit:'mm',format:'a4',compress:true});
  pdf.setProperties({title,creator:'CIS · Powered by WiSync'});
  const margin=8,pageW=210-2*margin,pageH=297-2*margin;
  const pxPerMm=canvas.width/pageW,slice=Math.floor(pageH*pxPerMm);
  for(let y=0,page=0;y<canvas.height;y+=slice,page++){
   const h=Math.min(slice,canvas.height-y);
   const part=document.createElement('canvas');part.width=canvas.width;part.height=h;
   const ctx=part.getContext('2d')!;ctx.fillStyle='#fff';ctx.fillRect(0,0,part.width,h);ctx.drawImage(canvas,0,y,canvas.width,h,0,0,canvas.width,h);
   if(page>0)pdf.addPage();
   pdf.addImage(part.toDataURL('image/jpeg',quality),'JPEG',margin,margin,pageW,h/pxPerMm,undefined,'FAST');
  }
  const url='data:application/pdf;base64,'+toBase64(pdf.output('arraybuffer'));
  if(url.length<LIMIT)return url;
 }
 throw new Error('O documento é demasiado grande para enviar; reduza o período do extracto.');
}
export const fileName=(s:string,ext='pdf')=>s.replace(/[^\w.-]+/g,'_').replace(/_+/g,'_')+'.'+ext;
