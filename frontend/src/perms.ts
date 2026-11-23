// Permissões do utilizador com sessão (vêm de /auth/me; a matriz está no servidor:
// App\Support\Permissions). A interface só esconde o que o servidor já recusa.
import {createContext,useContext} from 'react';

export type Perm='ver'|'mutuarios.editar'|'contratos.abrir'|'cobrancas'|'cobrancas.data'|'caixa'|'caixa.estornar'
 |'extractos'|'extractos.geral'|'mensagens'|'relatorios'|'definicoes'|'utilizadores';

export const PermsContext=createContext<string[]>([]);
export function useCan(){const p=useContext(PermsContext);return (...any:Perm[])=>any.some(x=>p.includes(x));}
