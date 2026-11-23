import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './style.css';
import './themes.css';

// Falhas de rede («Failed to fetch») passam a mensagem clara em português, em todos os ecrãs.
const nativeFetch=window.fetch.bind(window);
window.fetch=async(...args:Parameters<typeof fetch>)=>{
 try{return await nativeFetch(...args);}
 catch(e){
  if(e instanceof DOMException&&e.name==='AbortError')throw e;
  if(e instanceof TypeError)throw new TypeError('Sem ligação ao servidor do CIS. Verifique a internet/VPN e tente de novo.');
  throw e;
 }
};
createRoot(document.getElementById('root')!).render(<React.StrictMode><App /></React.StrictMode>);